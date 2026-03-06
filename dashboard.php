<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');

// Handle clearing session error via AJAX
if (isset($_GET['clear_error'])) {
    unset($_SESSION['validation_error']);
    echo 'cleared';
    exit();
}

$pdo = getPDO();

// ===== POST ACTIONS (run before any output) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    if(isset($_POST['delete_viewing_id'])){
        $id = intval($_POST['delete_viewing_id']);
        $stmt = $pdo->prepare("DELETE FROM viewing_schedules WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php");
        exit();
    }

    if(isset($_POST['reserve_single'])){
        $id = intval($_POST['reserve_single']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['cancel_reserved'])){
        $id = intval($_POST['cancel_reserved']);
        $stmt = $pdo->prepare("UPDATE vehicles 
            SET status='Available', viewing_date = NULL, viewing_person = NULL
            WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['schedule_viewing'])){
        $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
        $date = $_POST['viewing_date'];
        $person = trim($_POST['viewing_person']);
        $today = date('Y-m-d');

        if($date >= $today && $person !== ''){
            // avoid problematic backslashes in SQL string
            $stmt = $pdo->prepare(
                "INSERT INTO viewing_schedules (vehicle_id, viewing_date, viewing_person) VALUES (?, ?, ?)"
            );
            $stmt->execute([$vehicleId, $date, $person]);
            header("Location: dashboard.php");
            exit();
        } else {
            echo "<script>alert('Invalid date or person name.');</script>";
        }
    }

    if(isset($_POST['priority_single'])){
        $id = intval($_POST['priority_single']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Priority' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['cancel_priority'])){
        $id = intval($_POST['cancel_priority']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Available' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    // Handle reservation creation
    if(isset($_POST['create_reservation'])){
        try {
            $firstName = validateRequired($_POST['first_name'] ?? '', 'First Name');
            $firstName = validateString($firstName, 'First Name', 2, 100);
            
            $middleName = validateString($_POST['middle'] ?? '', 'Middle Name', 0, 100);
            
            $lastName = validateRequired($_POST['last_name'] ?? '', 'Last Name');
            $lastName = validateString($lastName, 'Last Name', 2, 100);
            
            $contact = validateRequired($_POST['contact'] ?? '', 'Contact');
            $contact = validatePhone($contact);
            
            $payment = validateRequired($_POST['reservation_payment'] ?? '', 'Reservation Payment');
            $payment = validateString($payment, 'Reservation Payment', 1, 200);
            
            $vehicleId = validateNumber($_POST['vehicle_id'] ?? '0', 'Vehicle ID', 1, 999999);
            $vehicleId = intval($vehicleId);
            
            // Verify vehicle exists and is available
            $vehicleCheck = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND status = ?');
            $vehicleCheck->execute([$vehicleId, 'Available']);
            if (!$vehicleCheck->fetch()) {
                throw new InvalidArgumentException('Vehicle not available for reservation');
            }
            
        } catch (InvalidArgumentException $e) {
            showValidationError($e->getMessage());
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Insert reservation record with vehicle_id
            $stmt = $pdo->prepare("
                INSERT INTO reservations (vehicle_id, first_name, middle_name, last_name, contact, reservation_payment) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId, $firstName, $middleName, $lastName, $contact, $payment
            ]);
            
            $reservationId = $pdo->lastInsertId();
            
            // Update vehicle status to Reserved
            $reserveStmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id=?");
            $reserveStmt->execute([$vehicleId]);
            
            $pdo->commit();
            
            // Log reservation creation
            $reservationData = [
                'reservation_id' => $reservationId,
                'vehicle_id' => $vehicleId,
                'customer_name' => trim($firstName . ' ' . $middleName . ' ' . $lastName),
                'contact' => $contact,
                'reservation_payment' => $payment,
                'vehicle_status_changed' => 'Available -> Reserved'
            ];
            add_audit($pdo, 'Reservation Created', json_encode($reservationData));
            
            echo "<script>alert('Reservation created successfully!'); window.location.href='dashboard.php';</script>";
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error creating reservation: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
            exit();
        }
    }
}

// Stats
$stmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles');
$stmt->execute();
$total = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE status=:status');
$stmt->execute([':status' => 'Available']);
$available = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(purchase_price),0) FROM vehicles WHERE status=:status');
$stmt->execute([':status' => 'Available']);
$inventoryValue = $stmt->fetchColumn();

require 'header.php';
displayValidationErrorIfExists();
?>
<?php /* duplicate POST processing removed, handled above */
if(isset($_POST['delete_viewing_id'])){
    $id = intval($_POST['delete_viewing_id']);
    $stmt = $pdo->prepare("DELETE FROM viewing_schedules WHERE id=:id");
    $stmt->execute([':id' => $id]);
    header("Location: dashboard.php");
    exit();
}

// ======================= HANDLE POST ACTIONS =======================

// Single Reserve
if(isset($_POST['reserve_single'])){
    $id = intval($_POST['reserve_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Reserved', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}

// Cancel reservation (no JS conflict and explicit handling)
if(isset($_POST['cancel_reserved'])){
    $id = intval($_POST['cancel_reserved']);
    
    // Get reservation details before cancellation for audit
    $reservationStmt = $pdo->prepare("
        SELECT r.*, v.brand, v.model, v.plate_number 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.vehicle_id = ?
    ");
    $reservationStmt->execute([$id]);
    $reservation = $reservationStmt->fetch();
    
    $stmt = $pdo->prepare("UPDATE vehicles 
        SET status=:status, viewing_date = NULL, viewing_person = NULL
        WHERE id=:id");
    $stmt->execute([':status' => 'Available', ':id' => $id]);
    
    // Log reservation cancellation
    if ($reservation) {
        $cancellationData = [
            'reservation_id' => $reservation['id'],
            'vehicle_id' => $id,
            'vehicle_info' => [
                'brand' => $reservation['brand'],
                'model' => $reservation['model'],
                'plate_number' => $reservation['plate_number']
            ],
            'customer_name' => trim($reservation['first_name'] . ' ' . $reservation['middle_name'] . ' ' . $reservation['last_name']),
            'contact' => $reservation['contact'],
            'reservation_payment' => $reservation['reservation_payment'],
            'vehicle_status_changed' => 'Reserved -> Available'
        ];
        add_audit($pdo, 'Reservation Cancelled', json_encode($cancellationData));
    }
    
    header("Location: dashboard.php"); exit();
}


// ================= SCHEDULE VIEWING =================
if(isset($_POST['schedule_viewing'])){

    $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $date = $_POST['viewing_date'];
    $person = trim($_POST['viewing_person']);
    $today = date('Y-m-d');

    if($date >= $today && $person !== ''){

        $stmt = $pdo->prepare("
            INSERT INTO viewing_schedules (vehicle_id, viewing_date, viewing_person)
            VALUES (:vehicle_id, :viewing_date, :viewing_person)
        ");

        $stmt->execute([
            ':vehicle_id' => $vehicleId,
            ':viewing_date' => $date,
            ':viewing_person' => $person
        ]);

        header("Location: dashboard.php");
        exit();
    } else {
        echo "<script>alert('Invalid date or person name.');</script>";
    }
}

// Priority Marking
if(isset($_POST['priority_single'])){
    $id = intval($_POST['priority_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Priority', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}

// Cancel priority marker
if(isset($_POST['cancel_priority'])){
    $id = intval($_POST['cancel_priority']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Available', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}



// ======================= FETCH DATA =======================

// Reserved units with reservation details
$stmt = $pdo->prepare("
    SELECT v.*, r.first_name, r.last_name, r.reservation_payment, r.created_at as reservation_date
    FROM vehicles v
    LEFT JOIN reservations r ON v.id = r.vehicle_id
    WHERE v.status=:status 
    ORDER BY v.created_at DESC");
$stmt->execute([':status' => 'Reserved']);
$reservedUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

//Reservation payments
$stmt = $pdo->prepare('SELECT * FROM reservations ORDER BY created_at DESC');
$stmt->execute();
$reservationPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Available units (for modals)
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE status=:status ORDER BY created_at DESC');
$stmt->execute([':status' => 'Available']);
$availableUnitsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
$availableUnitsReservedAll = $availableUnitsAll;
$availableUnitsPriorityAll = $availableUnitsAll;
$availableUnitsViewingAll = $availableUnitsAll;

// pagination parameters
$perPage = 16;
$reservedPage = max(1, intval($_GET['reserved_preview_page'] ?? 1));
$reservedModalPage = max(1, intval($_GET['reserved_modal_page'] ?? 1));
$priorityModalPage = max(1, intval($_GET['priority_modal_page'] ?? 1));
$viewingModalPage = max(1, intval($_GET['viewing_modal_page'] ?? 1));

$stmt = $pdo->prepare("
    SELECT vs.*, v.brand, v.model, v.plate_number, v.year, v.selling_price
    FROM viewing_schedules vs
    LEFT JOIN vehicles v ON vs.vehicle_id = v.id
    ORDER BY vs.viewing_date ASC");
$stmt->execute();
$viewingUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Priority units
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE status=:status ORDER BY created_at DESC');
$stmt->execute([':status' => 'Priority']);
$priorityUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ======================= HANDLE SEARCH =======================

$openReservedModal = false;
$openViewingModal = false;
$openPriorityModal = false;

// Reserved modal search
if(isset($_GET['search_reserved'])){
    $openReservedModal = true;
    $value = trim($_GET['reserved_value'] ?? '');
    $field = $_GET['reserved_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsReservedAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Priority modal search
if(isset($_GET['search_priority'])){
    $openPriorityModal = true;
    $value = trim($_GET['priority_value'] ?? '');
    $field = $_GET['priority_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsPriorityAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Viewing modal search
if(isset($_GET['search_viewing'])){
    $openViewingModal = true;
    $value = trim($_GET['viewing_value'] ?? '');
    $field = $_GET['viewing_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsViewingAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Keep modal open when paginating within it (non-JS fallback)
if (isset($_GET['reserved_modal_page'])) $openReservedModal = true;
if (isset($_GET['priority_modal_page'])) $openPriorityModal = true;
if (isset($_GET['viewing_modal_page'])) $openViewingModal = true;

// apply pagination slices
$reservedTotal = count($reservedUnits);
$reservedUnits = array_slice($reservedUnits, ($reservedPage-1)*$perPage, $perPage);

$availableReservedTotal = count($availableUnitsReservedAll);
$availableUnitsReserved = array_slice($availableUnitsReservedAll, ($reservedModalPage-1)*$perPage, $perPage);

$availablePriorityTotal = count($availableUnitsPriorityAll);
$availableUnitsPriority = array_slice($availableUnitsPriorityAll, ($priorityModalPage-1)*$perPage, $perPage);

$availableViewingTotal = count($availableUnitsViewingAll);
$availableUnitsViewing = array_slice($availableUnitsViewingAll, ($viewingModalPage-1)*$perPage, $perPage);


// old generic cancellation handler removed; specific handlers are above

?>


<body>
<div class = "dashboard-container-stat">
<!-- Stats Section -->
        <div class="dashboard-stats-container">
            <div class="dashboard-stat">
                <h5>Total Vehicles</h5>
                <h3><?php echo $total; ?></h3>
            </div>

            <div class="dashboard-stat">
                <h5>Available</h5>
                <h3><?php echo $available; ?></h3>
            </div>

            <div class="dashboard-stat">
                <h5>Inventory Value</h5>
                <h3>₱<?php echo number_format($inventoryValue,2); ?></h3>
            </div>
        </div>
</div>

<!-- ===== Dashboard Action Buttons ===== -->
 <div class="dashboard-actions-container">
<div class="dashboard-actions">
    <button class="reserved-btn" onclick="openModal('reservedModal')">Reserved Units</button>
    <button class="viewing-btn" onclick="openModal('viewingModal')">Viewing Schedule</button>
    <button class="priority-btn" onclick="openModal('priorityModal')">Priority to Sell</button>
</div>
</div>

<!-- ===== Dashboard Preview Section ===== -->
<div class="dashboard-preview-container">

    <!-- Reserved Units Preview -->
    <div>
        <div class="preview-title">Reserved Units Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Customer Name</th>
                        <th>Reservation Payment</th>
                        <th>Reservation Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($reservedUnits as $unit): ?>
                    <tr>
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td><?= htmlspecialchars(($unit['first_name'] ?? '') . ' ' . ($unit['last_name'] ?? '')) ?: 'N/A' ?></td>
                        <td><?= htmlspecialchars($unit['reservation_payment'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['reservation_date'] ?? 'N/A') ?></td>
						<td>
						<form method="POST" class="cancel-reservation-form">
						<input type="hidden" name="cancel_reserved" value="<?= $unit['id'] ?>">
						<?= getCSRFInput() ?>
							<button type="submit" class="cancel-btn">x</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if(isset($reservedTotal) && $reservedTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($reservedTotal / $perPage);
                    $startPage = max(1, $reservedPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      echo '<a href="?' . http_build_query(array_merge($_GET, ['reserved_preview_page' => 1])) . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['reserved_preview_page' => $p])); ?>" class="ajax-link <?= $p==$reservedPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      echo '<a href="?' . http_build_query(array_merge($_GET, ['reserved_preview_page' => $totalPages])) . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Viewing Schedule Preview -->
    <div>
        <div class="preview-title">Viewing Schedule Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Viewing Date</th>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Person</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                   <?php foreach($viewingUnits as $unit): ?>
<tr>
    <td><?= htmlspecialchars($unit['viewing_date']) ?></td>
    <td><?= $unit['vehicle_id'] ? htmlspecialchars($unit['year']) : '—' ?></td>
    <td>
        <?= $unit['vehicle_id'] 
            ? htmlspecialchars($unit['brand'].' '.$unit['model']) 
            : '<strong>All Available Cars</strong>' ?>
    </td>
    <td><?= $unit['vehicle_id'] ? htmlspecialchars($unit['plate_number']) : '—' ?></td>
    <td><?= htmlspecialchars($unit['viewing_person']) ?></td>
    <td>
        <form method="POST" class="cancel-viewing-form">
            <input type="hidden" name="delete_viewing_id" value="<?= $unit['id'] ?>">
            <?= getCSRFInput() ?>
            <button type="submit" class="cancel-btn">x</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Priority to Sell Preview -->
    <div>
        <div class="preview-title">Priority to Sell Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Price</th>
                        <th>Status</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($priorityUnits as $unit): ?>
                    <tr>
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>₱<?= number_format($unit['selling_price'],2) ?></td>
                        <td><?= $unit['status'] ?></td>
						<td>
						<form method="POST" class="cancel-priority-form">
						<input type="hidden" name="cancel_priority" value="<?= $unit['id'] ?>">
						<?= getCSRFInput() ?>
							<button type="submit" class="cancel-btn">x</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ================= MODALS ================= -->

<!-- Reserved Modal -->
<div id="reservedModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('reservedModal')">&times;</span>
        <h3>Reserved Units & Available Inventory</h3>

        <form method="GET">
            <input type="text" name="reserved_value" placeholder="Search available..." value="<?= htmlspecialchars($_GET['reserved_value'] ?? '') ?>">
            <select name="reserved_field">
                <option value="brand" <?= (($_GET['reserved_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['reserved_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['reserved_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_reserved">Search</button>
        </form>
        <h4>Available Units</h4>
         <?php if($availableReservedTotal > $perPage): ?>
                <div class="pagination" style="text-align:center;margin:10px 0;">
                    <?php
                        $showPages = 5; // Number of page links to show
                        $totalPages = ceil($availableReservedTotal / $perPage);
                        $startPage = max(1, $reservedModalPage - floor($showPages / 2));
                        $endPage = min($totalPages, $startPage + $showPages - 1);
                        
                        if ($endPage - $startPage < $showPages - 1) {
                          $startPage = max(1, $endPage - $showPages + 1);
                        }
                        
                        // First page
                        if ($startPage > 1) {
                          $href = '?reserved_modal_page=1';
                          if (isset($_GET['search_reserved'])) {
                            $href = '?search_reserved=1&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') . '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') . '&reserved_modal_page=1';
                          }
                          echo '<a href="' . $href . '" class="ajax-link">1</a>';
                          if ($startPage > 2) {
                            echo '<span class="ajax-link" style="cursor:default;">...</span>';
                          }
                        }
                        
                        // Page range
                        for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                        <?php
                            $href = '?reserved_modal_page=' . $p;
                            if (isset($_GET['search_reserved'])) {
                                $href =
                                    '?search_reserved=1' .
                                    '&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') .
                                    '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') .
                                    '&reserved_modal_page=' . $p;
                            }
                        ?>
                        <a href="<?= $href ?>" class="ajax-link <?= $p==$reservedModalPage?'active':'' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    
                    <?php
                        // Last page
                        if ($endPage < $totalPages) {
                          if ($endPage < $totalPages - 1) {
                            echo '<span class="ajax-link" style="cursor:default;">...</span>';
                          }
                          $href = '?reserved_modal_page=' . $totalPages;
                          if (isset($_GET['search_reserved'])) {
                            $href = '?search_reserved=1&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') . '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') . '&reserved_modal_page=' . $totalPages;
                          }
                          echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                        }
                    ?>
                </div>
            <?php endif; ?>

        <form method="POST">
            <?= getCSRFInput() ?>
            <div class="search-results-container">
                <?php foreach($availableUnitsReserved as $unit): ?>
                    <div class="result-card" onclick="openReservationModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['brand']) ?>', '<?= htmlspecialchars($unit['model']) ?>', '<?= htmlspecialchars($unit['plate_number']) ?>', '<?= number_format($unit['selling_price'], 2) ?>')">
                      
                        <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                        Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                        Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                    
                    </div>
                <?php endforeach; ?>
            </div>

           
            <br>
           
        </form>
    </div>
</div>

<!-- Viewing Schedule Modal -->
<div id="viewingModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('viewingModal')">&times;</span>
        <h3>Viewing Schedule</h3>

        <form method="GET">
            <input type="text" name="viewing_value" placeholder="Search available units..." value="<?= htmlspecialchars($_GET['viewing_value'] ?? '') ?>">
            <select name="viewing_field">
                <option value="brand" <?= (($_GET['viewing_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['viewing_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['viewing_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_viewing">Search</button>
        </form>

        <?php if($availableViewingTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($availableViewingTotal / $perPage);
                    $startPage = max(1, $viewingModalPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      $href = '?viewing_modal_page=1';
                      if (isset($_GET['search_viewing'])) {
                        $href = '?search_viewing=1&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') . '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') . '&viewing_modal_page=1';
                      }
                      echo '<a href="' . $href . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php
                        $href = '?viewing_modal_page=' . $p;
                        if (isset($_GET['search_viewing'])) {
                            $href =
                                '?search_viewing=1' .
                                '&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') .
                                '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') .
                                '&viewing_modal_page=' . $p;
                        }
                    ?>
                    <a href="<?= $href ?>" class="ajax-link <?= $p==$viewingModalPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      $href = '?viewing_modal_page=' . $totalPages;
                      if (isset($_GET['search_viewing'])) {
                        $href = '?search_viewing=1&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') . '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') . '&viewing_modal_page=' . $totalPages;
                      }
                      echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

        <div class="search-results-container">
            <div class="view-card" onclick="openViewingScheduleModal('', 'All Available Cars', '', '—')">
                <strong>All Available Cars</strong><br>
                Plate: —<br>
            
            </div>

            <?php foreach($availableUnitsViewing as $unit): ?>
                <div class="view-card" onclick="openViewingScheduleModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['brand']) ?>', '<?= htmlspecialchars($unit['model']) ?>', '<?= htmlspecialchars($unit['plate_number']) ?>')">
                    <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                    Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                    Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                   
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Priority Modal -->
<div id="priorityModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('priorityModal')">&times;</span>
        <h3>Priority to Sell</h3>

        <form method="GET">
            <input type="text" name="priority_value" placeholder="Search available units..." value="<?= htmlspecialchars($_GET['priority_value'] ?? '') ?>">
            <select name="priority_field">
                <option value="brand" <?= (($_GET['priority_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['priority_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['priority_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_priority">Search</button>
        </form>

        <?php if($availablePriorityTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($availablePriorityTotal / $perPage);
                    $startPage = max(1, $priorityModalPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      $href = '?priority_modal_page=1';
                      if (isset($_GET['search_priority'])) {
                        $href = '?search_priority=1&priority_value=' . urlencode($_GET['priority_value'] ?? '') . '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') . '&priority_modal_page=1';
                      }
                      echo '<a href="' . $href . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php
                        $href = '?priority_modal_page=' . $p;
                        if (isset($_GET['search_priority'])) {
                            $href =
                                '?search_priority=1' .
                                '&priority_value=' . urlencode($_GET['priority_value'] ?? '') .
                                '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') .
                                '&priority_modal_page=' . $p;
                        }
                    ?>
                    <a href="<?= $href ?>" class="ajax-link <?= $p==$priorityModalPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      $href = '?priority_modal_page=' . $totalPages;
                      if (isset($_GET['search_priority'])) {
                        $href = '?search_priority=1&priority_value=' . urlencode($_GET['priority_value'] ?? '') . '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') . '&priority_modal_page=' . $totalPages;
                      }
                      echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

        <div class="search-results-container">
            <?php foreach($availableUnitsPriority as $unit): ?>
                <form method="POST" style="margin:0;">
                    <?= getCSRFInput() ?>
                    <input type="hidden" name="priority_single" value="<?= $unit['id'] ?>">
                    <div class="priority-card" onclick="this.closest('form').submit()">
                        <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                        Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                        Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Reservation Modal -->
<div id="reservationModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('reservationModal')">&times;</span>
        <h3>Reserve Vehicle</h3>
        
        <div id="reservationDetails" style="margin-bottom: 20px;">
            <!-- Vehicle details will be populated here -->
        </div>
        
        <form method="POST" id="reservationForm">
            <input type="hidden" name="vehicle_id" id="reservationVehicleId">
            <?= getCSRFInput() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle">
                </div>
                
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Contact</label>
                    <input type="text" name="contact" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Reservation Payment</label>
                    <input type="text" name="reservation_payment" required>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="create_reservation" class="action-btn">Submit Reservation</button>
                <button type="button" onclick="closeModal('reservationModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Viewing Create Modal -->
<div id="viewingCreateModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('viewingCreateModal')">&times;</span>
        <h3>Create Viewing Schedule</h3>

        <div id="viewingDetails" style="margin-bottom: 20px;">
            <!-- Vehicle details will be populated here -->
        </div>

        <form method="POST" id="viewingCreateForm">
            <input type="hidden" name="vehicle_id" id="viewingVehicleId">
            <?= getCSRFInput() ?>
            <div class="form-row">
                <div class="form-group" style="width:100%;">
                    <label>Viewing Date</label>
                    <input type="date" name="viewing_date" id="viewingDateInput" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="width:100%;">
                    <label>Person Name</label>
                    <input type="text" name="viewing_person" placeholder="Person Name" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="schedule_viewing" class="action-btn">Create Schedule</button>
                <button type="button" onclick="closeModal('viewingCreateModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= JAVASCRIPT ================= -->
<!-- ================= JS MODAL CONTROL ================= -->
<script>
function openModal(id){document.getElementById(id).style.display="flex";}
function closeModal(id){document.getElementById(id).style.display="none";}
window.addEventListener("click",function(e){document.querySelectorAll(".modal").forEach(function(m){if(e.target===m)m.style.display="none";});});
<?php if($openReservedModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('reservedModal');});<?php endif; ?>
<?php if($openViewingModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('viewingModal');});<?php endif; ?>
<?php if($openPriorityModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('priorityModal');});<?php endif; ?>

function toggleVehicleSelect(){
    const type = document.getElementById('viewingType').value;
    const wrapper = document.getElementById('vehicleSelectWrapper');

    if(type === 'all'){
        wrapper.style.display = 'none';
        wrapper.querySelector('select').value = '';
    } else {
        wrapper.style.display = 'block';
    }
}

// Open reservation modal with vehicle details
function openReservationModal(vehicleId, brand, model, plate, price) {
    // Set vehicle ID in hidden field
    document.getElementById('reservationVehicleId').value = vehicleId;
    
    // Populate vehicle details
    const detailsDiv = document.getElementById('reservationDetails');
    detailsDiv.innerHTML = `
        <div style="background: rgba(255, 215, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4 style="color: #ffd700; margin-bottom: 10px;">Vehicle Details</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div><strong>Brand:</strong> ${brand}</div>
                <div><strong>Model:</strong> ${model}</div>
                <div><strong>Plate:</strong> ${plate}</div>
                <div><strong>Price:</strong> ₱${price}</div>
            </div>
        </div>
    `;
    
    // Open modal
    openModal('reservationModal');
}

// Open viewing schedule create modal with vehicle details
function openViewingScheduleModal(vehicleId, brand, model, plate) {
    document.getElementById('viewingVehicleId').value = vehicleId || '';

    const detailsDiv = document.getElementById('viewingDetails');
    const title = (brand + ' ' + (model || '')).trim();
    detailsDiv.innerHTML = `
        <div style="background: rgba(255, 215, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4 style="color: #ffd700; margin-bottom: 10px;">Vehicle Details</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div><strong>Vehicle:</strong> ${title || 'All Available Cars'}</div>
                <div><strong>Plate:</strong> ${plate || '—'}</div>
            </div>
        </div>
    `;

    // default to today
    const dateInput = document.getElementById('viewingDateInput');
    if (dateInput && !dateInput.value) {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        dateInput.value = `${yyyy}-${mm}-${dd}`;
    }

    openModal('viewingCreateModal');
}

// ================= AJAX HELPERS =================
async function fetchFragment(url, selector) {
    const res = await fetch(url, {credentials: 'same-origin'});
    const text = await res.text();
    const temp = document.createElement('div');
    temp.innerHTML = text;
    const fragment = temp.querySelector(selector);
    return fragment ? fragment.innerHTML : '';
}

function updatePreview(html) {
    const container = document.querySelector('.dashboard-preview-container');
    if (container) container.innerHTML = html;
}

// link and form interception
window.addEventListener('click', function(e){
    const a = e.target.closest('a.ajax-link');
    if(a){
        e.preventDefault();
        const url = a.href;
        const modal = a.closest('.modal');
        if(modal){
            // update only this modal's content
            const selector = '#' + modal.id + ' .modal-content';
            fetchFragment(url, selector).then(html=>{
                modal.querySelector('.modal-content').innerHTML = html;
            });
        } else {
            fetchFragment(url, '.dashboard-preview-container').then(updatePreview);
        }
    }
});

window.addEventListener('submit', function(e){
    const form = e.target;
    // ignore forms inside modals (let them submit normally)
    if(form.matches('form') && !form.closest('.modal')){
        const isCancelReservation = form.classList.contains('cancel-reservation-form');
        const isCancelViewing = form.classList.contains('cancel-viewing-form');
        const isCancelPriority = form.classList.contains('cancel-priority-form');

        if (isCancelReservation || isCancelViewing || isCancelPriority) {
            e.preventDefault();

            var existing = document.getElementById('actionConfirmModal');
            if (existing) existing.remove();

            var title = 'Please Confirm';
            var message = 'Are you sure?';
            if (isCancelReservation) {
                title = 'Cancel Reservation';
                message = 'Cancel this reservation?';
            } else if (isCancelViewing) {
                title = 'Cancel Viewing';
                message = 'Cancel this viewing schedule?';
            } else if (isCancelPriority) {
                title = 'Remove Priority';
                message = 'Remove this vehicle from Priority to Sell?';
            }

            var modalHTML =
              '<div id="actionConfirmModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
                '<div style="background:white;padding:30px;border-radius:10px;max-width:420px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);">' +
                  '<h3 style="color:#d4af37;margin-bottom:15px;">' + title.replace(/</g, '&lt;') + '</h3>' +
                  '<p style="color:#444;margin-bottom:25px;line-height:1.5;">' + message.replace(/</g, '&lt;') + '</p>' +
                  '<div style="display:flex;gap:10px;justify-content:center;">' +
                    '<button id="actionConfirmYes" style="background:#d4af37;color:#000;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">OK</button>' +
                    '<button id="actionConfirmNo" style="background:#ccc;color:#333;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">Cancel</button>' +
                  '</div>' +
                '</div>' +
              '</div>';

            document.body.insertAdjacentHTML('beforeend', modalHTML);

            var root = document.getElementById('actionConfirmModal');
            var yesBtn = document.getElementById('actionConfirmYes');
            var noBtn = document.getElementById('actionConfirmNo');

            function close() {
                if (root) root.remove();
            }

            yesBtn.addEventListener('click', function () {
                close();
                form.submit();
            });

            noBtn.addEventListener('click', function () {
                close();
            });

            return;
        }

        e.preventDefault();
        const method = (form.method||'get').toLowerCase();
        // use attribute so named controls don't override the property
        const action = form.getAttribute('action') || window.location.href;
        if(method === 'get'){
            const params = new URLSearchParams(new FormData(form));
            const url = action.split('?')[0] + '?' + params.toString();
            fetchFragment(url, '.dashboard-preview-container').then(updatePreview);
        } else {
            fetch(action, {method:'post', body:new FormData(form), credentials:'same-origin'})
                .then(()=> fetchFragment(window.location.href, '.dashboard-preview-container'))
                .then(updatePreview);
        }
    }
});
</script>
</body>