@echo off
REM Progressive Enrichment Runner for DOJ Cases (Windows Batch Version)
REM
REM This script runs the enrichment process for all tables in the optimal order.
REM It's designed to be run via Windows Task Scheduler to continuously enrich new data.
REM
REM Usage:
REM     run_enrichment.bat [--dry-run] [--limit-per-table LIMIT] [--verbose]
REM
REM The script processes tables in this order:
REM 1. case_metadata (foundation data)
REM 2. participants (people involved)
REM 3. case_agencies (investigating agencies)
REM 4. charges (legal statutes)
REM 5. financial_actions (money flows)
REM 6. victims (impact assessment)
REM 7. quotes (narrative elements)
REM 8. themes (categorization)

setlocal enabledelayedexpansion

REM Parse command line arguments
set DRY_RUN=false
set LIMIT_PER_TABLE=
set VERBOSE=false
set TABLES=

:parse_args
if "%1"=="" goto :start
if "%1"=="--dry-run" (
    set DRY_RUN=true
    shift
    goto :parse_args
)
if "%1"=="--limit-per-table" (
    set LIMIT_PER_TABLE=%2
    shift
    shift
    goto :parse_args
)
if "%1"=="--verbose" (
    set VERBOSE=true
    shift
    goto :parse_args
)
if "%1"=="--tables" (
    set TABLES=%2
    shift
    shift
    goto :parse_args
)
shift
goto :parse_args

:start
echo ========================================
echo Progressive Enrichment Runner Starting
echo ========================================
echo Dry run mode: %DRY_RUN%
echo Limit per table: %LIMIT_PER_TABLE%
echo Verbose: %VERBOSE%
echo Tables: %TABLES%
echo.

REM Define the enrichment order and batch sizes
set ENRICHMENT_ORDER=case_metadata:20 participants:15 case_agencies:15 charges:15 financial_actions:15 victims:10 quotes:10 themes:10

REM Create log file with timestamp
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "YYYY=%dt:~2,2%"
set "MM=%dt:~4,2%"
set "DD=%dt:~6,2%"
set "HH=%dt:~8,2%"
set "MIN=%dt:~10,2%"
set "SEC=%dt:~12,2%"
set "LOGFILE=enrichment_%YYYY%%MM%%DD%_%HH%%MIN%%SEC%.log"

echo Starting enrichment at %YYYY%-%MM%-%DD% %HH%:%MIN%:%SEC% > %LOGFILE%

REM Run enrichment for each table
set PASS_NUM=1
for %%i in (%ENRICHMENT_ORDER%) do (
    for /f "tokens=1,2 delims=:" %%a in ("%%i") do (
        set TABLE_NAME=%%a
        set DEFAULT_LIMIT=%%b
        
        echo ======================================== >> %LOGFILE%
        echo Pass %PASS_NUM%: !TABLE_NAME! >> %LOGFILE%
        echo ======================================== >> %LOGFILE%
        
        echo Processing !TABLE_NAME!...
        
        REM Build command
        set CMD=python enrich_cases.py --table !TABLE_NAME!
        
        REM Use override limit if provided, otherwise use default
        if not "%LIMIT_PER_TABLE%"=="" (
            set CMD=!CMD! --limit %LIMIT_PER_TABLE%
        ) else (
            set CMD=!CMD! --limit !DEFAULT_LIMIT!
        )
        
        REM Add dry-run flag if specified
        if "%DRY_RUN%"=="true" (
            set CMD=!CMD! --dry-run
        )
        
        REM Add verbose flag if specified
        if "%VERBOSE%"=="true" (
            set CMD=!CMD! --verbose
        )
        
        echo Running: !CMD! >> %LOGFILE%
        echo Running: !CMD!
        
        REM Execute the command
        !CMD! >> %LOGFILE% 2>&1
        set EXIT_CODE=!ERRORLEVEL!
        
        if !EXIT_CODE!==0 (
            echo ✅ Successfully completed !TABLE_NAME! enrichment >> %LOGFILE%
            echo ✅ Successfully completed !TABLE_NAME! enrichment
        ) else (
            echo ❌ Failed to enrich !TABLE_NAME! >> %LOGFILE%
            echo ❌ Failed to enrich !TABLE_NAME!
        )
        
        REM Pause between passes (except for the last one)
        if %PASS_NUM% LSS 8 (
            echo Pausing 5 seconds before next pass... >> %LOGFILE%
            echo Pausing 5 seconds before next pass...
            timeout /t 5 /nobreak > nul
        )
        
        set /a PASS_NUM+=1
    )
)

echo ======================================== >> %LOGFILE%
echo Enrichment Process Complete >> %LOGFILE%
echo ======================================== >> %LOGFILE%

echo.
echo ========================================
echo Enrichment Process Complete
echo ========================================
echo Log file: %LOGFILE%
echo.

REM Show final progress
python -c "import sqlite3; conn = sqlite3.connect('doj_cases.db'); cursor = conn.cursor(); cursor.execute('SELECT COUNT(*) FROM cases WHERE verified_1960 = 1'); verified = cursor.fetchone()[0]; print(f'Total verified cases: {verified}'); tables = ['case_metadata', 'participants', 'case_agencies', 'charges', 'financial_actions', 'victims', 'quotes', 'themes']; [print(f'{table}: {cursor.execute(f\"SELECT COUNT(*) FROM {table}\").fetchone()[0]}/{verified}') for table in tables]; conn.close()"

endlocal 