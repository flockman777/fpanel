<?php
/** Layout.head - $title, $active, $bodyClass */
$title    = $title    ?? 'Beranda';
$active   = $active   ?? '';
$bodyClass= $bodyClass?? '';
$__v      = trim((string)@file_get_contents(FPANEL_ROOT . '/app/public/assets/VERSION') ?: '') ?: '1';
?><!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title) ?> &middot; FPanel</title>
<link rel="icon" href="/assets/img/favicon.svg?v=<?= $__v ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/img/favicon-192.png?v=<?= $__v ?>" sizes="192x192">
<link rel="shortcut icon" href="/assets/img/favicon.ico?v=<?= $__v ?>">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= $__v ?>">
<script>
(function(){var m=localStorage.getItem('fpanel.theme')||'light';
if(m==='dark'){document.documentElement.classList.add('dark');}
document.documentElement.style.colorScheme=m;})();
</script>
<script defer src="/assets/js/icons.js?v=<?= $__v ?>"></script>
<script defer src="/assets/js/panel.js?v=<?= $__v ?>"></script>
</head>
<body class="min-h-full bg-slate-50 text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-100 <?= htmlspecialchars($bodyClass) ?>">
