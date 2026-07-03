# Extract HVP

A PHP utility that extracts standalone `.h5p` packages from Moodle course backups.

It rebuilds activities created with the legacy **mod_hvp** plugin and recovers packaged H5P content from the Moodle content bank / core **mod_h5pactivity** backup records.

By default, recovered packages include content and media only. Use `--keeplibraries` to also include H5P library folders. For rebuilt legacy **mod_hvp** activities, the script includes only the needed backed-up library folders and generated `library.json` metadata.

## Requirements

- PHP 8+
- Zip extension enabled
- 7-Zip installed

## Usage

### PHP CLI

```text
Usage:
  php extract-hvp.php <backup.mbz> <output-folder> [--keeplibraries]

Examples:
  php extract-hvp.php backup.mbz output
  php extract-hvp.php backup.mbz output --keeplibraries
```

### Windows GUI

The Windows GUI lets end users choose:

- the Moodle course backup `.mbz` file
- the output folder
- whether recovered packages should keep H5P libraries

The GUI source is in `src/extract_hvp_gui.py`.

## Build Windows EXE

Requirements for building:

- Python 3.13+
- PyInstaller

Build:

```powershell
.\build-windows.ps1
```

The standalone executable is created at:

```text
dist\Extract-HVP.exe
```
