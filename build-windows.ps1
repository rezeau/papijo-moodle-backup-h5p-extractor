$ErrorActionPreference = "Stop"

$python = if ($env:PYTHON) { $env:PYTHON } else { "python" }

& $python -m PyInstaller `
    --onefile `
    --windowed `
    --clean `
    --name Extract-HVP `
    .\src\extract_hvp_gui.py
