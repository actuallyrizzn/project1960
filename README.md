# DOJ Case Analysis Project

A comprehensive system for scraping, analyzing, and exploring Department of Justice press releases, with a focus on 18 USC 1960 (money transmission) and cryptocurrency-related cases.

## Features

- **Web Scraper**: Automatically scrapes DOJ press releases with idempotent operation
- **AI Verification**: Uses Venice AI to verify case classifications for 18 USC 1960 and crypto mentions
- **Web Interface**: Modern Flask/Bootstrap UI for exploring and filtering cases
- **File Server**: Simple HTTP server for file downloads
- **Database**: SQLite storage with efficient querying and filtering

## Setup

### Prerequisites

- Python 3.8+
- Venice AI API key

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd ocp2-project
```

2. Install dependencies:
```bash
pip install -r requirements.txt
```

3. Set up environment variables:
```bash
cp env.example .env
```

4. Edit `.env` and add your Venice AI API key:
```
VENICE_API_KEY=your_actual_api_key_here
```

### Configuration

The following environment variables can be configured in `.env`:

- `VENICE_API_KEY`: Your Venice AI API key (required)
- `DATABASE_NAME`: Database filename (default: `doj_cases.db`)
- `FLASK_DEBUG`: Enable Flask debug mode (default: `False`)
- `FLASK_HOST`: Flask server host (default: `0.0.0.0`)
- `FLASK_PORT`: Flask server port (default: `5000`)
- `FILE_SERVER_PORT`: File server port (default: `8000`)
- `FILE_SERVER_DIRECTORY`: Directory to serve files from (default: `.`)

## Usage

### 1. Scrape DOJ Press Releases

```bash
python scraper.py
```

The scraper will:
- Fetch press releases from the DOJ API
- Filter for cases mentioning 18 USC 1960 or cryptocurrency
- Store results in the database
- Skip already processed content (idempotent)

### 2. Verify Cases with AI

```bash
python 1960-verify.py
```

Options:
- `--dry-run`: Test without making API calls
- `--limit N`: Process only N cases
- `--verbose`: Enable detailed logging
- `--help`: Show help

### 3. Launch Web Interface

```bash
python app.py
```

Visit `http://localhost:5000` to access the web interface.

### 4. File Server (Optional)

```bash
python file_server.py
```

Serves files from the current directory on port 8000.

## Project Structure

```
ocp2-project/
├── scraper.py          # DOJ press release scraper
├── 1960-verify.py      # AI verification script
├── app.py              # Flask web application
├── file_server.py      # Simple file server
├── doj_cases.db        # SQLite database
├── requirements.txt    # Python dependencies
├── .env                # Environment variables (create from env.example)
├── .gitignore          # Git ignore rules
├── env.example         # Environment template
├── templates/          # Flask templates
│   ├── base.html
│   ├── index.html
│   ├── cases.html
│   └── case_detail.html
└── README.md           # This file
```

## Database Schema

The database contains the following fields:
- `id`: Unique identifier
- `title`: Press release title
- `content`: Full press release content
- `date`: Publication date
- `url`: Original URL
- `mentions_1960`: Boolean flag for 18 USC 1960 mentions
- `mentions_crypto`: Boolean flag for cryptocurrency mentions
- `verified_1960`: AI verification result for 18 USC 1960
- `verified_crypto`: AI verification result for cryptocurrency
- `classification`: AI classification notes

## Security Notes

- Never commit your `.env` file to version control
- The `.gitignore` file excludes sensitive files
- API keys are loaded from environment variables
- Database files are excluded from version control

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

[Add your license information here] 