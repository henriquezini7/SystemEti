<?php
require_once __DIR__ . '/../app/auth.php';
// Ponto de entrada do app: direciona para a interface mobile (SystemETI).
if (auth_user()) { redirect('home.php'); }
redirect('entrar.php');
