<?php
if ($argc < 3) {
    die("Usage:\n  php extract-hvp.php <backup.mbz|extracted-folder> <output-folder>\n\nExamples:\n  php extract-hvp.php backup.mbz output\n  php extract-hvp.php extracted-backup output\n");
}

if (!class_exists('ZipArchive')) {
    die("PHP Zip extension is not enabled.\n");
}

function createTemporaryBackupDirectory(): string
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract-hvp-' . getmypid() . '-' . bin2hex(random_bytes(8));

    if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        die("Cannot create temporary folder.\n");
    }

    return $tempDir;
}

function deleteTemporaryBackupDirectory(string $directory): void
{
    $realDirectory = realpath($directory);
    $realTempDir = realpath(sys_get_temp_dir());

    if ($realDirectory === false || $realTempDir === false) {
        return;
    }

    $expectedPrefix = $realTempDir . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realDirectory, $expectedPrefix) || !str_starts_with(basename($realDirectory), 'extract-hvp-')) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($realDirectory);
}

function extractArchiveWithSevenZip(string $archivePath, string $destinationDir): void
{
    $sevenZipExecutables = [
        '7z',
        '7za',
        'C:\\Program Files\\7-Zip\\7z.exe',
        'C:\\Program Files (x86)\\7-Zip\\7z.exe',
    ];

    $lastOutput = [];

    foreach ($sevenZipExecutables as $executable) {
        if (str_contains($executable, DIRECTORY_SEPARATOR) && !is_file($executable)) {
            continue;
        }

        $command = escapeshellarg($executable)
            . ' x -y -o' . escapeshellarg($destinationDir)
            . ' ' . escapeshellarg($archivePath)
            . ' 2>&1';

        $output = [];
        exec($command, $output, $exitCode);
        $lastOutput = $output;

        if ($exitCode === 0) {
            return;
        }
    }

    echo "Cannot extract archive with 7-Zip: $archivePath\n";
    if ($lastOutput !== []) {
        echo implode("\n", $lastOutput) . "\n";
    }
    die("Make sure 7-Zip is installed and available in PATH.\n");
}

function isExtractedBackupDirectory(string $directory): bool
{
    return is_file($directory . DIRECTORY_SEPARATOR . 'files.xml')
        && is_dir($directory . DIRECTORY_SEPARATOR . 'files')
        && is_dir($directory . DIRECTORY_SEPARATOR . 'activities');
}

function findExtractedBackupDirectory(string $directory): ?string
{
    if (isExtractedBackupDirectory($directory)) {
        return realpath($directory) ?: null;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        if (!$file->isDir() || $file->isLink()) {
            continue;
        }

        $path = $file->getPathname();
        if (isExtractedBackupDirectory($path)) {
            return realpath($path) ?: null;
        }
    }

    return null;
}

function findSingleNestedArchiveCandidate(string $directory): ?string
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $candidates = [];

    foreach ($files as $file) {
        if ($file->isFile()) {
            $candidates[] = $file->getPathname();
        }
    }

    if (count($candidates) !== 1) {
        return null;
    }

    return $candidates[0];
}

$inputPath = realpath($argv[1]);
$outputDir = $argv[2];

if ($inputPath !== false && is_dir($inputPath)) {
    $backupDir = $inputPath;
} elseif ($inputPath !== false && is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'mbz') {
    $temporaryBackupDir = createTemporaryBackupDirectory();
    register_shutdown_function('deleteTemporaryBackupDirectory', $temporaryBackupDir);

    echo "Extracting MBZ backup to temporary folder...\n";
    extractArchiveWithSevenZip($inputPath, $temporaryBackupDir);

    $backupDir = findExtractedBackupDirectory($temporaryBackupDir);
    $nestedArchive = findSingleNestedArchiveCandidate($temporaryBackupDir);

    if ($backupDir === null && $nestedArchive !== null) {
        echo "Extracting nested archive from MBZ backup...\n";
        extractArchiveWithSevenZip($nestedArchive, $temporaryBackupDir);
        $backupDir = findExtractedBackupDirectory($temporaryBackupDir);
    }

    if ($backupDir === null) {
        die("files.xml not found in extracted MBZ backup.\n");
    }
} else {
    die("Backup folder not found.\n");
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    die("Cannot create output folder.\n");
}
$outputDir = realpath($outputDir);

if ($outputDir === false) {
    die("Cannot resolve output folder.\n");
}

$filesXmlPath = $backupDir . DIRECTORY_SEPARATOR . 'files.xml';
$filesDir = $backupDir . DIRECTORY_SEPARATOR . 'files';
$activitiesDir = $backupDir . DIRECTORY_SEPARATOR . 'activities';

if (!is_file($filesXmlPath)) {
    die("files.xml not found.\n");
}

if (!is_dir($filesDir)) {
    die("files folder not found.\n");
}

if (!is_dir($activitiesDir)) {
    die("activities folder not found.\n");
}

$filesXml = simplexml_load_file($filesXmlPath);

if ($filesXml === false) {
    die("Cannot parse files.xml.\n");
}

$mediaByItem = [];

foreach ($filesXml->file as $file) {
    $component = (string) $file->component;
    $filearea = (string) $file->filearea;
    $itemid = (string) $file->itemid;
    $filename = (string) $file->filename;
    $filepath = (string) $file->filepath;
    $contenthash = (string) $file->contenthash;
    $filesize = (int) $file->filesize;

    if ($component !== 'mod_hvp') {
        continue;
    }

    if ($filearea !== 'content') {
        continue;
    }

    if ($filename === '.' || $filename === '' || $filesize === 0) {
        continue;
    }

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
    if ($activity === false) {
        echo "Skipping unreadable HVP activity XML: $hvpXmlFile\n";
        continue;
    }

    if (!isset($activity->hvp)) {
        continue;
    }

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

    $encodedH5pJson = json_encode($h5pJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encodedH5pJson === false) {
        echo "Cannot encode h5p.json for item $itemid / $title: " . json_last_error_msg() . "\n";
        $zip->close();
        continue;
    }

    if (!$zip->addFromString('h5p.json', $encodedH5pJson)) {
        echo "Cannot add h5p.json to: $target\n";
        $zip->close();
        continue;
    }

    if (!$zip->addFromString('content/content.json', $jsonContent)) {
        echo "Cannot add content/content.json to: $target\n";
        $zip->close();
        continue;
    }

    $addedMedia = 0;

    foreach ($mediaByItem[$itemid] ?? [] as $media) {
        $hash = $media['contenthash'];

        $source1 = $filesDir . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash;
        $source2 = $filesDir . DIRECTORY_SEPARATOR . $hash;

        if (is_file($source1)) {
            $source = $source1;
        } elseif (is_file($source2)) {
            $source = $source2;
        } else {
            echo "Missing media file for item $itemid: $hash\n";
            continue;
        }

        $internalPath = 'content/' . ltrim($media['filepath'], '/\\') . $media['filename'];
        $internalPath = str_replace('\\', '/', $internalPath);

        if ($zip->addFile($source, $internalPath)) {
            $addedMedia++;
        } else {
            echo "Cannot add media file for item $itemid: $internalPath\n";
        }
    }

    if (!$zip->close()) {
        echo "Cannot finalize: $target\n";
        continue;
    }

    echo "Created OK: $target ($addedMedia media files)\n";
    $count++;
}

echo "\nDone. Rebuilt $count H5P package(s).\n";
