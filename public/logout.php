<?php
require_once __DIR__ . '/../app/auth.php';
logout_user();
redirect('login.php');
