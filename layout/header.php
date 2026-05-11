<?php require_once __DIR__ . '/../app_config.php'; ?>
<?php
if (!isset($shop) || !$shop) {
    if (isset($_GET['shop']) && $_GET['shop']) {
        $shop = $_GET['shop'];
    } elseif (isset($_SESSION['shop']) && $_SESSION['shop']) {
        $shop = $_SESSION['shop'];
    } else {
        $shop = '';
    }
}
?>
<style>
    .nav-wrapper {
        max-width: 1300px;
        margin: 0 auto;
        margin-top: 20px;
    }
    .nav-box {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }
    .nav-link {
        text-align: center;
        padding: 12px 0;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        text-decoration: none;
        border-right: 1px solid #e5e7eb;
        transition: all 0.2s ease;
    }
    .nav-link:last-child {
        border-right: none;
        border-radius: 0 10px 10px 0;
    }
    .nav-link:first-child {
        border-radius: 10px 0 0 10px;
    }
    .nav-link:hover {
        background: #f9fafb;
    }
    .nav-link.active {
        background: #eff6ff;
        color: #2563eb;
        font-weight: 600;
    }
</style>
<nav class="navbar-wrapper">
    <div class="navbar">
        <div class="nav-wrapper">
            <div class="nav-box">
                <a class="nav-link" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/index.php?shop=<?php echo urlencode($shop); ?>">Templates</a>
                <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/app_settings.php?shop=<?php echo urlencode($shop); ?>" class="nav-link">App Settings</a>
                <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/view_logs.php?shop=<?php echo urlencode($shop); ?>" class="nav-link">View Logs</a>
            </div>
        </div>
    </div>
</nav>
<script>
    (function() {
        var links = document.querySelectorAll(".nav-link");
        var currentPath = window.location.pathname.split("/").pop();
        if (currentPath === "" || currentPath === "/") {
            currentPath = "index.php";
        }
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute("href");
            var linkPath = href.split("/").pop();
            linkPath = linkPath.split("?")[0];
            links[i].classList.remove("active");
            if (linkPath === currentPath) {
                links[i].classList.add("active");
            }
        }
    })();
</script>