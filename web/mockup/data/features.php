<?php /* Helper PHP: baca sumber data fitur yang sama dengan assets/js/features.js */
function fpanel_features(): array {
  static $data = null;
  if ($data === null) {
    $data = json_decode(file_get_contents(__DIR__ . '/features.json'), true) ?: ['features' => [], 'categories' => []];
  }
  return $data;
}
