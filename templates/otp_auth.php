
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">
    <link rel="stylesheet"href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=arrow_back" />
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/notifycspp/templates/styles/style.css">
    <title>OTP Authentication</title>
</head>
<body>
    <div class="container-box">
        <div class="page-header">
            <div class="header-left">
                <a class="material-symbols-outlined" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/index.php">arrow_back</a>
                <div class="page-title">OTP Authentication</div>
            </div>
        </div>
        <form>
            <div class="content-wrapper">
                <div class="left-panel">
                    <div class="tabs">
                        <div class="tab-btn active" data-tab="sms">SMS</div>
                    </div>
                    <div class="tab-content active" id="sms">
                        <textarea id="smsBox" placeholder="Enter SMS template..."></textarea>
                        <div class="btn-group">
                            <button class="submit-btn">Save Template</button>
                            <button type="button" class="cancel-btn"
                                onclick="location.href='/index.php'">Cancel</button>
                        </div>
                    </div>
                </div>
                <div class="right-panel">
                    <div class="variable-box">
                        <h4>Liquid Variables</h4>
                        <p>Please replace the Template Variable {#var#} with liquid variables mentioned below.</p>
                        <div class="variable-divider"></div>
                        <div class="variable-list">
                            <div class="variable-item">{{ otp }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <script>
        document.querySelectorAll('.variable-item').forEach(item => {
            item.addEventListener('click', function () {
                const text = this.innerText.trim();
                navigator.clipboard.writeText(text).then(() => {
                   console.log(text + " copied");
                }).catch(err => {
                    console.error("Copy failed:", err);
                });
            });
        });
        const tabs = document.querySelectorAll('.tab-btn');
        const contents = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });
        const radioButtons = document.querySelectorAll('input[name="media_source"]');
        const urlBox = document.getElementById('mediaUrlBox');
        const fileBox = document.getElementById('mediaFileBox');
        radioButtons.forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'url' && radio.checked) {
                    urlBox.style.display = 'flex';
                    fileBox.style.display = 'none';
                } else if (radio.value === 'file' && radio.checked) {
                    urlBox.style.display = 'none';
                    fileBox.style.display = 'flex';
                }
            });
        });
    </script>
</body>

</html>