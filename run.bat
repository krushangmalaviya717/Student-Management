@echo off
if exist "C:\xampp\php\php.exe" (
    "C:\xampp\php\php.exe" start.php
) else (
    php start.php
)
pause
