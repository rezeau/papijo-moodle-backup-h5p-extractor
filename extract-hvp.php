<?php
if ($argc < 3) {
    die("Usage: php extract-hvp.php extracted_backup_folder output_folder\n");
}

$backupDir = realpath($argv[1]);
$outputDir = $argv[2];

if (!$backupDir || !is_dir($backupDir)) {
    die("Backup folder not found.\n");
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
$outputDir = realpath($outputDir);

$filesXmlPath = $backupDir . DIRECTORY_SEPARATOR . 'files.xml';
$filesDir = $backupDir . DIRECTORY_SEPARATOR . 'files';
$activitiesDir = $backupDir . DIRECTORY_SEPARATOR . 'activities';

if (!file_exists($filesXmlPath)) die("files.xml not found.\n");
if (!is_dir($filesDir)) die("files folder not found.\n");
if (!is_dir($activitiesDir)) die("activities folder not found.\n");

$filesXml = simplexml_load_file($filesXmlPath);

$mediaByItem = [];

foreach ($filesXml->file as $file) {
    $component = (string) $file->component;
    $filearea = (string) $file->filearea;
    $itemid = (string) $file->itemid;
    $filename = (string) $file->filename;
    $filepath = (string) $file->filepath;
    $contenthash = (string) $file->contenthash;
    $filesize = (int) $file->filesize;

    if ($component !== 'mod_hvp') continue;
    if ($filearea !== 'content') continue;
    if ($filename === '.' || $filename === '' || $filesize === 0) continue;

    $mediaByItem[$itemid][] = [
        'filename' => $filename,
        'filepath' => $filepath,
        'contenthash' => $contenthash,
    ];
}

$hvpXmlFiles = glob($activitiesDir . DIRECTORY_SEPARATOR . 'hvp_*' . DIRECTORY_SEPARATOR . 'hvp.xml');

$count = 0;

foreach ($hvpXmlFiles as $hvpXmlFile) {
    $activity = simplexml_load_file($hvpXmlFile);
    if (!$activity || !isset($activity->hvp)) continue;

    $hvp = $activity->hvp;

    $itemid = (string) $hvp['id'];
    $title = (string) $hvp->name;
    $machineName = (string) $hvp->machine_name;
    $major = (int) $hvp->major_version;
    $minor = (int) $hvp->minor_version;
    $jsonContent = (string) $hvp->json_content;
json_decode($jsonContent);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Invalid JSON for item $itemid / $title: " . json_last_error_msg() . "\n";
    continue;
}
    if ($itemid === '' || $machineName === '' || $jsonContent === '') {
        echo "Skipping incomplete HVP activity: $hvpXmlFile\n";
        continue;
    }

    $safeTitle = preg_replace('/[<>:"\/\\\\|?*]/', '_', $title);
    $safeTitle = trim($safeTitle);
    if ($safeTitle === '') {
        $safeTitle = "hvp_item_$itemid";
    }

    $target = $outputDir . DIRECTORY_SEPARATOR . $safeTitle . ".h5p";

    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "Cannot create: $target\n";
        continue;
    }

    $h5pJson = [
        'title' => $title,
        'language' => 'en',
        'mainLibrary' => $machineName,
        'embedTypes' => ['div'],
        'license' => (string) $hvp->license ?: 'U',
        'preloadedDependencies' => [
            [
                'machineName' => $machineName,
                'majorVersion' => $major,
                'minorVersion' => $minor,
            ]
        ],
    ];

    $zip->addFromString(
        'h5p.json',
        json_encode($h5pJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $zip->addFromString(
        'content/content.json',
        $jsonContent
    );

    $addedMedia = 0;

    foreach ($mediaByItem[$itemid] ?? [] as $media) {
        $hash = $media['contenthash'];

        $source1 = $filesDir . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash;
        $source2 = $filesDir . DIRECTORY_SEPARATOR . $hash;

        if (file_exists($source1)) {
            $source = $source1;
        } elseif (file_exists($source2)) {
            $source = $source2;
        } else {
            echo "Missing media file for item $itemid: $hash\n";
            continue;
        }

        $internalPath = 'content/' . ltrim($media['filepath'], '/\\') . $media['filename'];
        $internalPath = str_replace('\\', '/', $internalPath);

        if ($zip->addFile($source, $internalPath)) {
            $addedMedia++;
        }
    }

    $zip->close();

    echo "Created OK: $target ($addedMedia media files)\n";
    $count++;
}

echo "\nDone. Rebuilt $count H5P package(s).\n";
