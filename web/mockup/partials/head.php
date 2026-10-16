<?php
/* $page = page id, $title = title, $bodyAttrs = body attributes, $area = user|admin */
$area  = $area  ?? 'user';
$page  = $page  ?? '';
$title = $title ?? 'fpanel';
$bodyAttrs = $bodyAttrs ?? '';
$__vf = dirname(__DIR__) . '/assets/VERSION';
$__v = trim(@file_get_contents($__vf) ?: '') ?: '1';
?><!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · fpanel</title>
<link rel="icon" href="assets/img/favicon.svg?v=<?= $__v ?>" type="image/svg+xml">
<link rel="alternate icon" href="assets/img/favicon-192.png?v=<?= $__v ?>" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="assets/img/favicon-192.png?v=<?= $__v ?>">
<link rel="shortcut icon" href="assets/img/favicon.ico?v=<?= $__v ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $__v ?>">
<script>
/* anti-flash: apply the theme before the first render */
(function(){var m=localStorage.getItem('fpanel.theme')||'light';
if(m==='dark'){document.documentElement.classList.add('dark');}
document.documentElement.style.colorScheme=m;})();
</script>
<script defer src="assets/js/app.js?v=<?= $__v ?>" data-base=""></script>
<script defer src="assets/js/features.js?v=<?= $__v ?>"></script>
<script defer src="assets/vendor/alpine.min.js?v=<?= $__v ?>"></script>
<script defer src="assets/vendor/htmx.min.js?v=<?= $__v ?>"></script>
<script defer src="assets/js/icons.js?v=<?= $__v ?>"></script>
</head>
<body class="min-h-full <?= $area === 'admin' ? 'admin' : 'user' ?>" <?= $bodyAttrs ?>>
