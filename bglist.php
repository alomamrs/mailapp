<?php
error_reporting(0);
@ini_set('display_errors','0');
/**
 * bglist.php — returns list of available backgrounds from the backgrounds/ folder
 * Returns JSON array of {id, label, file}
 * Built-in ones have known thumb colors; custom uploads get a generated color.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/Portal.php';
Portal::guard();

header('Content-Type: application/json');

$dir = __DIR__ . '/backgrounds/';
$builtinThumbs = [
    'docusign'  => 'linear-gradient(135deg,#f6f4f0 50%,#26b5e8 50%)',
    'outlook'   => 'linear-gradient(135deg,#03194a 50%,#0078d4 50%)',
    'onedrive'  => 'linear-gradient(135deg,#041e42 50%,#00b4f0 50%)',
    'teams'     => 'linear-gradient(135deg,#1a0533 50%,#7b83eb 50%)',
    'voicemail' => 'linear-gradient(135deg,#0a0a14 50%,#7c3aed 50%)',
];

// Fallback thumb colors for custom uploads (cycle through these)
$fallbackColors = [
    '#e63022','#2563eb','#16a34a','#9333ea',
    '#ea580c','#0891b2','#be185d','#ca8a04',
    '#0f766e','#7c3aed','#b45309','#1d4ed8',
];

$results = [];
if (is_dir($dir)) {
    $files = glob($dir . '*.html');
    sort($files);
    $customIndex = 0;
    foreach ($files as $f) {
        $id    = basename($f, '.html');
        if ($id === '') continue;
        $label = ucfirst(str_replace(['-','_'], ' ', $id));
        $thumb = $builtinThumbs[$id]
               ?? 'linear-gradient(135deg,' . $fallbackColors[$customIndex % count($fallbackColors)] . ' 50%,#222 50%)';
        if (!isset($builtinThumbs[$id])) $customIndex++;
        $results[] = [
            'id'    => $id,
            'label' => $label,
            'thumb' => $thumb,
        ];
    }
}

echo json_encode($results);
