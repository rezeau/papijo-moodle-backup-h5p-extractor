# Extract HVP

A PHP utility that extracts standalone `.h5p` packages from Moodle course backups.

It rebuilds activities created with the legacy **mod_hvp** plugin and copies packaged H5P content from the Moodle content bank / core **mod_h5pactivity** backup records.

By default, rebuilt legacy packages include content and media only. Use `--keeplibraries` to also include the needed backed-up H5P library folders and generated `library.json` metadata.

## Requirements

- PHP 8+
- Zip extension enabled
- 7-Zip installed

## Usage

```text
Usage:
  php extract-hvp.php <backup.mbz> <output-folder> [--keeplibraries]

Examples:
  php extract-hvp.php backup.mbz output
  php extract-hvp.php backup.mbz output --keeplibraries
```
