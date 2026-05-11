<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
if (function_exists('set_time_limit')) {
    @set_time_limit(15);
}
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
if (!$shop) {
    die("Shop not found");
}
session_write_close();
$pdo = getDatabaseConnection();
$configTable = $prefix . 'shopify_sms_notification_App_Log_Details';

$recordsPerPage = 8;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;


if (isset($_GET['fetch_logs'])) {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    
    $ajax_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($ajax_page - 1) * $recordsPerPage;
   
    $countSql = "SELECT COUNT(*) as total FROM $configTable WHERE shop = :shop";
    $countParams = array(':shop' => $shop);
    
    $sql = "SELECT id, updated_time, items_name, phone_num, action_name, api_name, response_result, notification_type
            FROM $configTable 
            WHERE shop = :shop";
    $params = array(':shop' => $shop);

    if (!empty($start_date)) {
        $dateCondition = " AND DATE(updated_time) >= :start_date_time";
        $sql .= $dateCondition;
        $countSql .= $dateCondition;
        $params[':start_date_time'] = $start_date;
        $countParams[':start_date_time'] = $start_date;
    }
    if (!empty($end_date)) {
        $dateCondition = " AND DATE(updated_time) <= :end_date_time";
        $sql .= $dateCondition;
        $countSql .= $dateCondition;
        $params[':end_date_time'] = $end_date;
        $countParams[':end_date_time'] = $end_date;
    }
    if (!empty($status)) {
        $statusCondition = " AND response_result = :status";
        $sql .= $statusCondition;
        $countSql .= $statusCondition;
        $params[':status'] = $status;
        $countParams[':status'] = $status;
    }
    if (!empty($search)) {
        $searchCondition = " AND (items_name LIKE :search OR phone_num LIKE :search)";
        $sql .= $searchCondition;
        $countSql .= $searchCondition;
        $params[':search'] = "%$search%";
        $countParams[':search'] = "%$search%";
    }

    $sql .= " ORDER BY updated_time DESC LIMIT " . intval($recordsPerPage) . " OFFSET " . intval($offset);

    try {
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalRows = intval($totalRecords['total']);
        $totalPages = ceil($totalRows / $recordsPerPage);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(array(
            'logs' => $logs,
            'current_page' => $ajax_page,
            'total_pages' => $totalPages,
            'total_records' => $totalRows
        ));
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(array('status' => 500, 'msg' => 'Failed to load logs', 'error' => $e->getMessage()));
    }
    exit;
}
if (isset($_GET['view_log'])) {
    $logId = $_GET['view_log'];
    try {
        $stmt = $pdo->prepare("SELECT api_request, api_response FROM $configTable WHERE id = :id AND shop = :shop");
        $stmt->execute(array(':id' => $logId, ':shop' => $shop));
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        header('Content-Type: application/json');
        echo json_encode(array(
            'api_request' => isset($log['api_request']) ? $log['api_request'] : '',
            'api_response' => isset($log['api_response']) ? $log['api_response'] : ''
        ));
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(array('status' => 500, 'msg' => 'Failed to load log details', 'error' => $e->getMessage()));
    }
    exit;
}

$offset = ($currentPage - 1) * $recordsPerPage;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM $configTable WHERE shop = :shop");
    $countStmt->execute(array(':shop' => $shop));
    $totalRows = intval($countStmt->fetch(PDO::FETCH_ASSOC)['total']);
    $totalPages = ceil($totalRows / $recordsPerPage);
} catch (Exception $e) {
    $totalRows = 0;
    $totalPages = 0;
}

$logs = array();
try {
    $stmt = $pdo->prepare("
        SELECT id, updated_time, items_name, phone_num, action_name, api_name, response_result, notification_type
        FROM $configTable  
        WHERE shop = :shop 
        ORDER BY updated_time DESC 
        LIMIT " . intval($recordsPerPage) . " OFFSET " . intval($offset) . "
    ");
    $stmt->execute(array(':shop' => $shop));
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logs = array();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App Logs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_view_logs.css">
</head>

<body>
    <?php
        include 'layout/header.php';
    ?>
    <div class="container-box">
        <div class="title-row">
            <div class="page-title">App Logs</div>
        </div>
        <div class="filter-bar">
            <div class="filter-left">
                <div class="filter-group">
                    <label>From:</label>
                    <input type="date" id="start_date" max="">
                </div>
                <div class="filter-group">
                    <label>To:</label>
                    <input type="date" id="end_date" max="">
                </div>
                <div class="filter-group">
                    <label>Status:</label>
                    <select id="status">
                        <option value="">All</option>
                        <option value="success">Success</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search:</label>
                    <input type="text" id="search" placeholder="Item Name or Phone">
                </div>
            </div>
            <div class="filter-actions">
                <button class="apply-btn" onclick="applyFilters(1)">Apply Filters</button>
                <button class="reset-btn" onclick="resetFilters()">Reset</button>
            </div>
        </div>
        <table class="custom-table" id="logsTable">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Name</th>
                    <th>Phone Number</th>
                    <th>Notification Type</th>
                    <th>Message Type</th>
                    <th>Status</th>
                    <th class="action-cell">Action</th>
                </tr>
            </thead>
            <tbody id="logsTableBody">
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log):
                        $messageType = (stripos(isset($log['api_name']) ? $log['api_name'] : '', 'whatsapp') !== false) ? 'WhatsApp' : 'SMS';
                        $phoneNum = isset($log['phone_num']) ? $log['phone_num'] : '-';
                        $name = isset($log['items_name']) ? $log['items_name'] : '-';
                        $notification_type = isset($log['notification_type']) ? $log['notification_type'] : '-';
                        if (empty($name) || $name == '') {
                            $name = '-';
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['updated_time'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($phoneNum, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($notification_type, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="status-<?php echo $log['response_result'] == 'success' ? 'success' : 'failed'; ?>">
                                    <?php echo ucfirst(isset($log['response_result']) ? $log['response_result'] : 'Unknown'); ?>
                                </span>
                            </td>
                            <td class="action-cell">
                                <button class="view-btn" onclick="viewLog(<?php echo $log['id']; ?>)">View</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr id="noDataRow">
                        <td colspan="7" style="text-align:center; padding: 40px;">No logs found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination-footer">
            <div class="pagination-info">
                Showing page <?php echo $currentPage; ?> of <?php echo $totalPages; ?> (<?php echo $totalRows; ?> total records)
            </div>
            <div class="pagination-controls">
                <button class="page-btn" id="prevPageBtn" onclick="goToPage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                    <span class="material-symbols-outlined">chevron_left</span> Previous
                </button>
                <span class="page-indicator">Page <?php echo $currentPage; ?></span>
                <button class="page-btn" id="nextPageBtn" onclick="goToPage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                    Next <span class="material-symbols-outlined">chevron_right</span>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div id="logModal" class="modal-overlay">
        <div class="modal-box">
            <button class="modal-close-btn" onclick="closeLogModal()">&times;</button>
            <div class="modal-left">
                <div class="modal-header">
                    <h3>API Request</h3>
                </div>
                <div class="request-box" id="apiRequest">
                    Loading...
                </div>
            </div>
            <div class="modal-right">
                <div class="modal-header">
                    <h3>API Response</h3>
                </div>
                <div class="response-box" id="apiResponse">
                    Loading...
                </div>
            </div>
        </div>
    </div>
    <script>
        var currentPage = <?php echo $currentPage; ?>;
        var totalPages = <?php echo $totalPages; ?>;
        
        var today = new Date().toISOString().split('T')[0];
        var startDateInput = document.getElementById('start_date');
        var endDateInput = document.getElementById('end_date');
        
        if (startDateInput) startDateInput.setAttribute('max', today);
        if (endDateInput) endDateInput.setAttribute('max', today);
        
        function updateEndDateMin() {
            if (!startDateInput || !endDateInput) return;
            var startDate = startDateInput.value;
            if (startDate) {
                endDateInput.setAttribute('min', startDate);
                if (endDateInput.value && endDateInput.value < startDate) {
                    endDateInput.value = '';
                    if (typeof shopify !== 'undefined' && shopify.toast) {
                        shopify.toast.show('End date cannot be before start date', { isError: true, duration: 3000 });
                    }
                }
            } else {
                endDateInput.removeAttribute('min');
            }
        }
        
        function validateStartDate() {
            if (!startDateInput || !endDateInput) return true;
            var startDate = startDateInput.value;
            var endDate = endDateInput.value;
            if (startDate && endDate && startDate > endDate) {
                startDateInput.classList.add('date-error');
                if (typeof shopify !== 'undefined' && shopify.toast) {
                    shopify.toast.show('Start date cannot be after end date', { isError: true, duration: 3000 });
                }
                return false;
            } else {
                startDateInput.classList.remove('date-error');
                return true;
            }
        }
        
        function validateEndDate() {
            if (!startDateInput || !endDateInput) return true;
            var startDate = startDateInput.value;
            var endDate = endDateInput.value;
            if (startDate && endDate && endDate < startDate) {
                endDateInput.classList.add('date-error');
                if (typeof shopify !== 'undefined' && shopify.toast) {
                    shopify.toast.show('End date cannot be before start date', { isError: true, duration: 3000 });
                }
                return false;
            } else {
                endDateInput.classList.remove('date-error');
                return true;
            }
        }
        
        if (startDateInput) {
            startDateInput.addEventListener('change', function () {
                updateEndDateMin();
                validateStartDate();
            });
        }
        if (endDateInput) {
            endDateInput.addEventListener('change', function () {
                validateEndDate();
            });
        }
        updateEndDateMin();
        
        function goToPage(page) {
            if (page < 1 || page > totalPages) {
                return;
            }
            currentPage = page;
            applyFilters(page);
        }
        
        function applyFilters(page) {
            var pageToLoad = page || 1;
            var start_date = startDateInput ? startDateInput.value : '';
            var end_date = endDateInput ? endDateInput.value : '';
            var status = document.getElementById('status') ? document.getElementById('status').value : '';
            var search = document.getElementById('search') ? document.getElementById('search').value : '';
            
            if (start_date && end_date && start_date > end_date) {
                if (typeof shopify !== 'undefined' && shopify.toast) {
                    shopify.toast.show('Please select valid date range (From date cannot be after To date)', { isError: true, duration: 3000 });
                }
                return;
            }
            
            var url = '?fetch_logs=1&shop=<?php echo $shop; ?>&start_date=' + encodeURIComponent(start_date) +
                      '&end_date=' + encodeURIComponent(end_date) + '&status=' + encodeURIComponent(status) +
                      '&search=' + encodeURIComponent(search) + '&page=' + pageToLoad;
            
            fetch(url)
                .then(function(res) { 
                    if (!res.ok) {
                        throw new Error('HTTP error ' + res.status);
                    }
                    return res.json(); 
                })
                .then(function(data) {
                    var logs = data.logs || [];
                    var totalPagesFromServer = data.total_pages || 1;
                    var totalRecords = data.total_records || logs.length;
                    var currentPageFromServer = data.current_page || pageToLoad;
                    
                    totalPages = totalPagesFromServer;
                    currentPage = currentPageFromServer;
                    
                    var tbody = document.getElementById('logsTableBody');
                    if (!tbody) return;
                    tbody.innerHTML = '';
                    
                    if (logs.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 40px;">No logs found</td></tr>';
                    } else {
                        for (var i = 0; i < logs.length; i++) {
                            var log = logs[i];
                            var messageType = (log.api_name && log.api_name.toLowerCase().indexOf('whatsapp') !== -1) ? 'WhatsApp' : 'SMS';
                            var phoneNum = log.phone_num || '-';
                            var name = log.items_name || '-';
                            var notificationType = log.notification_type || '-';
                            var displayDate = log.updated_time || '-';
                            var isSuccess = (log.response_result === 'success' || log.response_result === 'Success');
                            var statusClass = isSuccess ? 'status-success' : 'status-failed';
                            var statusText = log.response_result ? log.response_result.charAt(0).toUpperCase() + log.response_result.slice(1) : 'Unknown';
                            
                            var row = document.createElement('tr');
                            row.innerHTML = '<td>' + escapeHtml(displayDate) + '</td>' +
                                            '<td>' + escapeHtml(name) + '</td>' +
                                            '<td>' + escapeHtml(phoneNum) + '</td>' +
                                            '<td>' + escapeHtml(notificationType) + '</td>' +
                                            '<td>' + escapeHtml(messageType) + '</td>' +
                                            '<td><span class="' + statusClass + '">' + escapeHtml(statusText) + '</span></td>' +
                                            '<td class="action-cell"><button class="view-btn" onclick="viewLog(' + log.id + ')">View</button></td>';
                            tbody.appendChild(row);
                        }
                    }
                    
                    updatePaginationFooter(totalPagesFromServer, totalRecords, currentPageFromServer);
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    if (typeof shopify !== 'undefined' && shopify.toast) {
                        shopify.toast.show('Error loading logs. Please try again.', { isError: true, duration: 3000 });
                    }
                });
        }
        
        function updatePaginationFooter(totalPagesCount, totalRecordsCount, currentPageNum) {
            var existingFooter = document.querySelector('.pagination-footer');
            if (existingFooter) {
                existingFooter.remove();
            }
            if (totalPagesCount <= 1) {
                return;
            }
            
            var table = document.getElementById('logsTable');
            if (!table) return;
            
            var footer = document.createElement('div');
            footer.className = 'pagination-footer';
            
            var prevDisabled = currentPageNum <= 1 ? 'disabled' : '';
            var nextDisabled = currentPageNum >= totalPagesCount ? 'disabled' : '';
            
            footer.innerHTML = 
                '<div class="pagination-info">' +
                    'Showing page ' + currentPageNum + ' of ' + totalPagesCount + ' (' + totalRecordsCount + ' total records)' +
                '</div>' +
                '<div class="pagination-controls">' +
                    '<button class="page-btn" onclick="goToPage(' + (currentPageNum - 1) + ')" ' + prevDisabled + '>' +
                        '<span class="material-symbols-outlined">chevron_left</span> Previous' +
                    '</button>' +
                    '<span class="page-indicator">Page ' + currentPageNum + '</span>' +
                    '<button class="page-btn" onclick="goToPage(' + (currentPageNum + 1) + ')" ' + nextDisabled + '>' +
                        'Next <span class="material-symbols-outlined">chevron_right</span>' +
                    '</button>' +
                '</div>';
            
            table.parentNode.insertBefore(footer, table.nextSibling);
        }
        
        function resetFilters() {
            if (startDateInput) startDateInput.value = '';
            if (endDateInput) endDateInput.value = '';
            var statusSelect = document.getElementById('status');
            var searchInput = document.getElementById('search');
            if (statusSelect) statusSelect.value = '';
            if (searchInput) searchInput.value = '';
            updateEndDateMin();
            currentPage = 1;
            applyFilters(1);
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function viewLog(logId) {
            var modal = document.getElementById('logModal');
            var requestBox = document.getElementById('apiRequest');
            var responseBox = document.getElementById('apiResponse');
            
            if (!modal || !requestBox || !responseBox) return;
            
            requestBox.textContent = 'Loading request details...';
            responseBox.textContent = 'Loading response details...';
            modal.style.display = 'flex';
            
            fetch('?view_log=' + logId + '&shop=<?php echo $shop; ?>')
                .then(function(res) { 
                    if (!res.ok) {
                        throw new Error('HTTP error ' + res.status);
                    }
                    return res.json(); 
                })
                .then(function(data) {
                    var requestText = data.api_request || 'No request data available';
                    try {
                        var parsedRequest = JSON.parse(requestText);
                        requestText = JSON.stringify(parsedRequest, null, 2);
                    } catch (e) {
                        // Not JSON, keep as is
                    }
                    requestBox.textContent = requestText;
                    
                    var responseText = data.api_response || 'No response data available';
                    try {
                        var parsedResponse = JSON.parse(responseText);
                        responseText = JSON.stringify(parsedResponse, null, 2);
                    } catch (e) {
                        // Not JSON, keep as is
                    }
                    responseBox.textContent = responseText;
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    requestBox.textContent = 'Error loading request details';
                    responseBox.textContent = 'Error loading response details';
                    if (typeof shopify !== 'undefined' && shopify.toast) {
                        shopify.toast.show('Error loading log details', { isError: true, duration: 3000 });
                    }
                });
        }
        
        function closeLogModal() {
            var modal = document.getElementById('logModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        var modal = document.getElementById('logModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    closeLogModal();
                }
            });
        }
    </script>
</body>
</html>