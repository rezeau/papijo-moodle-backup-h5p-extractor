<?php
if ($argc < 3) {
    die("Usage:\n  php papijo-moodle-backup-h5p-extractor.php <backup.mbz> <output-folder> [--keeplibraries]\n\nExample:\n  php papijo-moodle-backup-h5p-extractor.php backup.mbz output --keeplibraries\n");
}

if (!class_exists('ZipArchive')) {
    die("PHP Zip extension is not enabled.\n");
}

function createTemporaryBackupDirectory(): string
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papijo-moodle-backup-h5p-extractor-' . getmypid() . '-' . bin2hex(random_bytes(8));

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
    if (!str_starts_with($realDirectory, $expectedPrefix) || !str_starts_with(basename($realDirectory), 'papijo-moodle-backup-h5p-extractor-')) {
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

function getStoredMoodleFilePath(string $filesDir, string $contenthash): ?string
{
    $source1 = $filesDir . DIRECTORY_SEPARATOR . substr($contenthash, 0, 2) . DIRECTORY_SEPARATOR . $contenthash;
    $source2 = $filesDir . DIRECTORY_SEPARATOR . $contenthash;

    if (is_file($source1)) {
        return $source1;
    }

    if (is_file($source2)) {
        return $source2;
    }

    return null;
}

function getSafeOutputName(string $name, string $fallback): string
{
    $safeName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $name);
    $safeName = trim((string) $safeName);

    if ($safeName === '') {
        return $fallback;
    }

    return $safeName;
}

function getUniqueOutputPath(string $outputDir, string $safeTitle, array &$usedOutputNames): string
{
    $baseName = $safeTitle;
    $suffix = 1;

    do {
        $fileName = $suffix === 1 ? $baseName . '.h5p' : $baseName . ' (' . $suffix . ').h5p';
        $key = strtolower($fileName);
        $target = $outputDir . DIRECTORY_SEPARATOR . $fileName;
        $suffix++;
    } while (isset($usedOutputNames[$key]) || file_exists($target));

    $usedOutputNames[$key] = true;

    return $target;
}

function loadContentBankNames(string $backupDir): array
{
    $contentBankPath = $backupDir . DIRECTORY_SEPARATOR . 'course' . DIRECTORY_SEPARATOR . 'contentbank.xml';

    if (!is_file($contentBankPath)) {
        return [];
    }

    $contentBankXml = simplexml_load_file($contentBankPath);

    if ($contentBankXml === false) {
        echo "Cannot parse content bank metadata: $contentBankPath\n";
        return [];
    }

    $names = [];

    foreach ($contentBankXml->content as $content) {
        $id = (string) $content['id'];
        $name = trim((string) $content->name);

        if ($id !== '' && $name !== '') {
            $names[$id] = $name;
        }
    }

    return $names;
}

function splitHvpLibraryList(string $value): array
{
    $items = [];

    foreach (explode(',', $value) as $item) {
        $item = trim($item);

        if ($item !== '') {
            $items[] = ['path' => $item];
        }
    }

    return $items;
}

function getHvpLibraryString(SimpleXMLElement $library, string $field): string
{
    $value = trim((string) $library->{$field});

    if ($value === '$@NULL@$') {
        return '';
    }

    return $value;
}

function getHvpLibraryJsonValue(SimpleXMLElement $library, string $field): mixed
{
    $value = getHvpLibraryString($library, $field);

    if ($value === '') {
        return null;
    }

    $decoded = json_decode($value);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $decoded;
}

function loadHvpLibraryMetadata(string $backupDir): array
{
    $librariesPath = $backupDir . DIRECTORY_SEPARATOR . 'activities' . DIRECTORY_SEPARATOR . 'hvp_libraries.xml';

    if (!is_file($librariesPath)) {
        return [];
    }

    $librariesXml = simplexml_load_file($librariesPath);

    if ($librariesXml === false) {
        echo "Cannot parse HVP library metadata: $librariesPath\n";
        return [];
    }

    $librariesById = [];
    $metadata = [
        'jsonByFolder' => [],
        'folderByKey' => [],
        'dependenciesByFolder' => [],
    ];

    foreach ($librariesXml->library as $library) {
        $id = (string) $library['id'];
        $machineName = (string) $library->machine_name;
        $majorVersion = (int) $library->major_version;
        $minorVersion = (int) $library->minor_version;

        if ($id === '' || $machineName === '') {
            continue;
        }

        $libraryJson = [
            'title' => (string) $library->title,
            'machineName' => $machineName,
            'majorVersion' => $majorVersion,
            'minorVersion' => $minorVersion,
            'patchVersion' => (int) $library->patch_version,
            'runnable' => (int) $library->runnable === 1,
            'fullscreen' => (int) $library->fullscreen === 1,
            'embedTypes' => array_values(array_filter(array_map('trim', explode(',', getHvpLibraryString($library, 'embed_types'))))),
        ];

        $preloadedJs = splitHvpLibraryList(getHvpLibraryString($library, 'preloaded_js'));
        if ($preloadedJs !== []) {
            $libraryJson['preloadedJs'] = $preloadedJs;
        }

        $preloadedCss = splitHvpLibraryList(getHvpLibraryString($library, 'preloaded_css'));
        if ($preloadedCss !== []) {
            $libraryJson['preloadedCss'] = $preloadedCss;
        }

        $dropLibraryCss = getHvpLibraryString($library, 'drop_library_css');
        if ($dropLibraryCss !== '') {
            $libraryJson['dropLibraryCss'] = $dropLibraryCss;
        }

        $semantics = getHvpLibraryJsonValue($library, 'semantics');
        if ($semantics !== null) {
            $libraryJson['semantics'] = $semantics;
        }

        $folder = $machineName . '-' . $majorVersion . '.' . $minorVersion;

        $librariesById[$id] = [
            'folder' => $folder,
            'machineName' => $machineName,
            'majorVersion' => $majorVersion,
            'minorVersion' => $minorVersion,
            'json' => $libraryJson,
            'dependencies' => $library->dependencies->dependency ?? [],
        ];
        $metadata['folderByKey'][$folder] = $folder;
    }

    foreach ($librariesById as $id => $library) {
        $dependencyGroups = [
            'preloaded' => [],
            'dynamic' => [],
            'editor' => [],
        ];

        foreach ($library['dependencies'] as $dependency) {
            $requiredLibraryId = (string) $dependency['required_library_id'];
            $dependencyType = (string) $dependency->dependency_type;

            if (!isset($librariesById[$requiredLibraryId]) || !isset($dependencyGroups[$dependencyType])) {
                continue;
            }

            $requiredLibrary = $librariesById[$requiredLibraryId];
            $dependencyGroups[$dependencyType][] = [
                'machineName' => $requiredLibrary['machineName'],
                'majorVersion' => $requiredLibrary['majorVersion'],
                'minorVersion' => $requiredLibrary['minorVersion'],
            ];
        }

        $libraryJson = $library['json'];

        if ($dependencyGroups['preloaded'] !== []) {
            $libraryJson['preloadedDependencies'] = $dependencyGroups['preloaded'];
        }

        if ($dependencyGroups['dynamic'] !== []) {
            $libraryJson['dynamicDependencies'] = $dependencyGroups['dynamic'];
        }

        if ($dependencyGroups['editor'] !== []) {
            $libraryJson['editorDependencies'] = $dependencyGroups['editor'];
        }

        $metadata['jsonByFolder'][$library['folder']] = $libraryJson;
        $metadata['dependenciesByFolder'][$library['folder']] = [
            'preloaded' => [],
            'dynamic' => [],
            'editor' => [],
        ];

        foreach ($library['dependencies'] as $dependency) {
            $requiredLibraryId = (string) $dependency['required_library_id'];
            $dependencyType = (string) $dependency->dependency_type;

            if (!isset($librariesById[$requiredLibraryId]) || !isset($metadata['dependenciesByFolder'][$library['folder']][$dependencyType])) {
                continue;
            }

            $metadata['dependenciesByFolder'][$library['folder']][$dependencyType][] = $librariesById[$requiredLibraryId]['folder'];
        }
    }

    return $metadata;
}

function getHvpLibraryFoldersForContent(array $metadata, string $machineName, int $majorVersion, int $minorVersion): array
{
    if (!isset($metadata['jsonByFolder'], $metadata['dependenciesByFolder'])) {
        return [];
    }

    $mainFolder = $machineName . '-' . $majorVersion . '.' . $minorVersion;

    if (!isset($metadata['jsonByFolder'][$mainFolder])) {
        return [];
    }

    $selected = [];
    $stack = [$mainFolder];

    while ($stack !== []) {
        $folder = array_pop($stack);

        if (isset($selected[$folder])) {
            continue;
        }

        $selected[$folder] = true;

        foreach (['preloaded', 'dynamic'] as $dependencyType) {
            foreach ($metadata['dependenciesByFolder'][$folder][$dependencyType] ?? [] as $dependencyFolder) {
                $stack[] = $dependencyFolder;
            }
        }
    }

    return array_keys($selected);
}

function isH5pLibraryArchivePath(string $internalPath): bool
{
    $normalizedPath = ltrim(str_replace('\\', '/', $internalPath), '/');
    $firstSegment = strtok($normalizedPath, '/');

    if ($firstSegment === false || $firstSegment === '' || $firstSegment === 'content' || $firstSegment === 'h5p.json') {
        return false;
    }

    return preg_match('/^[A-Za-z0-9_.-]+-\d+\.\d+$/', $firstSegment) === 1;
}

function copyH5pPackageWithoutLibraries(string $source, string $target): bool
{
    $sourceZip = new ZipArchive();
    if ($sourceZip->open($source) !== true) {
        return false;
    }

    $targetZip = new ZipArchive();
    if ($targetZip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $sourceZip->close();
        return false;
    }

    for ($index = 0; $index < $sourceZip->numFiles; $index++) {
        $entry = $sourceZip->statIndex($index);

        if ($entry === false || !isset($entry['name'])) {
            continue;
        }

        $entryName = $entry['name'];

        if (isH5pLibraryArchivePath($entryName)) {
            continue;
        }

        if (str_ends_with($entryName, '/')) {
            $targetZip->addEmptyDir(rtrim($entryName, '/'));
            continue;
        }

        $stream = $sourceZip->getStream($entryName);

        if ($stream === false) {
            $targetZip->close();
            $sourceZip->close();
            return false;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false || !$targetZip->addFromString($entryName, $contents)) {
            $targetZip->close();
            $sourceZip->close();
            return false;
        }
    }

    $targetClosed = $targetZip->close();
    $sourceZip->close();

    return $targetClosed;
}

$inputPath = realpath($argv[1]);
$outputDir = $argv[2];
$keepLibraries = false;

foreach (array_slice($argv, 3) as $option) {
    if ($option === '--keeplibraries' || $option === '--keep-libraries') {
        $keepLibraries = true;
        continue;
    }

    die("Unknown option: $option\n");
}

if ($inputPath !== false && is_file($inputPath) && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'mbz') {
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
    die("MBZ backup file not found.\n");
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
$contentBankNames = loadContentBankNames($backupDir);
$hvpLibraryMetadata = $keepLibraries ? loadHvpLibraryMetadata($backupDir) : [];
$hvpLibraryFilesByFolder = [];
$h5pPackageFiles = [];
$h5pPackageHashes = [];
$usedOutputNames = [];

foreach ($filesXml->file as $file) {
    $component = (string) $file->component;
    $filearea = (string) $file->filearea;
    $itemid = (string) $file->itemid;
    $filename = (string) $file->filename;
    $filepath = (string) $file->filepath;
    $contenthash = (string) $file->contenthash;
    $filesize = (int) $file->filesize;

    if ($filename === '.' || $filename === '' || $filesize === 0) {
        continue;
    }

    if ($component === 'contentbank' && $filearea === 'public' && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'h5p') {
        $packageFile = [
            'title' => $contentBankNames[$itemid] ?? pathinfo($filename, PATHINFO_FILENAME),
            'fallback' => "contentbank_item_$itemid",
            'contenthash' => $contenthash,
            'label' => 'content bank',
        ];

        if (isset($h5pPackageHashes[$contenthash])) {
            $h5pPackageFiles[$h5pPackageHashes[$contenthash]] = $packageFile;
        } else {
            $h5pPackageHashes[$contenthash] = count($h5pPackageFiles);
            $h5pPackageFiles[] = $packageFile;
        }

        continue;
    }

    if ($component === 'mod_h5pactivity' && $filearea === 'package' && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'h5p') {
        if (isset($h5pPackageHashes[$contenthash])) {
            continue;
        }

        $h5pPackageHashes[$contenthash] = count($h5pPackageFiles);
        $h5pPackageFiles[] = [
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'fallback' => "h5pactivity_item_$itemid",
            'contenthash' => $contenthash,
            'label' => 'H5P activity',
        ];
        continue;
    }

    if ($keepLibraries && $component === 'mod_hvp' && $filearea === 'libraries') {
        if (strtolower($filename) === 'library.json') {
            continue;
        }

        $internalPath = ltrim($filepath, '/\\') . $filename;
        $internalPath = str_replace('\\', '/', $internalPath);
        $libraryFolder = strtok($internalPath, '/');

        if ($internalPath !== '' && $libraryFolder !== false) {
            $hvpLibraryFilesByFolder[$libraryFolder][] = [
                'contenthash' => $contenthash,
                'internalPath' => $internalPath,
            ];
        }

        continue;
    }

    if ($component !== 'mod_hvp') {
        continue;
    }

    if ($filearea !== 'content') {
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

    $safeTitle = getSafeOutputName($title, "hvp_item_$itemid");

    $target = getUniqueOutputPath($outputDir, $safeTitle, $usedOutputNames);

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

    $hvpLibraryFolders = $keepLibraries
        ? getHvpLibraryFoldersForContent($hvpLibraryMetadata, $machineName, $major, $minor)
        : [];

    foreach ($hvpLibraryFolders as $folder) {
        $libraryJson = $hvpLibraryMetadata['jsonByFolder'][$folder] ?? null;

        if ($libraryJson === null) {
            continue;
        }

        $encodedLibraryJson = json_encode($libraryJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encodedLibraryJson === false) {
            echo "Cannot encode library.json for $folder: " . json_last_error_msg() . "\n";
            continue;
        }

        if (!$zip->addFromString($folder . '/library.json', $encodedLibraryJson)) {
            echo "Cannot add library metadata to: $target ($folder)\n";
        }
    }

    $addedLibraryFiles = 0;

    foreach ($hvpLibraryFolders as $folder) {
        foreach ($hvpLibraryFilesByFolder[$folder] ?? [] as $libraryFile) {
            $source = getStoredMoodleFilePath($filesDir, $libraryFile['contenthash']);

            if ($source === null) {
                echo "Missing library file: {$libraryFile['contenthash']}\n";
                continue;
            }

            if ($zip->addFile($source, $libraryFile['internalPath'])) {
                $addedLibraryFiles++;
            } else {
                echo "Cannot add library file to: $target ({$libraryFile['internalPath']})\n";
            }
        }
    }

    $addedMedia = 0;

    foreach ($mediaByItem[$itemid] ?? [] as $media) {
        $hash = $media['contenthash'];

        $source = getStoredMoodleFilePath($filesDir, $hash);

        if ($source === null) {
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

    if ($keepLibraries) {
        echo "Created OK: $target ($addedMedia media files, $addedLibraryFiles library files)\n";
    } else {
        echo "Created OK: $target ($addedMedia media files)\n";
    }
    $count++;
}

foreach ($h5pPackageFiles as $packageFile) {
    $source = getStoredMoodleFilePath($filesDir, $packageFile['contenthash']);

    if ($source === null) {
        echo "Missing {$packageFile['label']} package file: {$packageFile['contenthash']}\n";
        continue;
    }

    $safeTitle = getSafeOutputName($packageFile['title'], $packageFile['fallback']);
    $target = getUniqueOutputPath($outputDir, $safeTitle, $usedOutputNames);

    if ($keepLibraries) {
        if (!copy($source, $target)) {
            echo "Cannot copy {$packageFile['label']} package to: $target\n";
            continue;
        }

        echo "Copied OK: $target ({$packageFile['label']})\n";
    } else {
        if (!copyH5pPackageWithoutLibraries($source, $target)) {
            echo "Cannot rebuild {$packageFile['label']} package without libraries: $target\n";
            continue;
        }

        echo "Created OK: $target ({$packageFile['label']}, without libraries)\n";
    }

    $count++;
}

echo "\nDone. Extracted $count H5P package(s).\n";
