<?php if (isset($_SESSION["userId"])): ?>
    <div onclick="toggleMenu()">
        👤 <?php echo $_SESSION["fullName"]; ?>
    </div>

    <div id="menu" class="hidden">
        <p><?php echo $_SESSION["email"]; ?></p>
        <a href="logout.php">Đăng xuất</a>
    </div>
<?php else: ?>
    <a href="login.php">Đăng nhập</a>
<?php endif; ?>