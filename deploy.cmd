@echo off
rem Double-click friendly wrapper around deploy.ps1 (skips the PowerShell execution policy for this run only).
rem Extra arguments are passed on, e.g.:  deploy.cmd -WhatIf
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy.ps1" %*
pause
