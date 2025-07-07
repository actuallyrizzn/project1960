# Installation Guide

This guide provides detailed instructions for installing and setting up Project1960 on your system. The installation process is designed to be straightforward and works on Windows, macOS, and Linux.

## 📋 Prerequisites

### System Requirements

- **Operating System**: Windows 10+, macOS 10.14+, or Linux (Ubuntu 18.04+)
- **Python**: Python 3.8 or higher
- **Memory**: Minimum 4GB RAM (8GB recommended)
- **Storage**: At least 2GB free disk space
- **Network**: Internet connection for API access and package installation

### Required Software

#### Python Installation

**Windows:**
1. Download Python from [python.org](https://www.python.org/downloads/)
2. Run the installer with "Add Python to PATH" checked
3. Verify installation: `python --version`

**macOS:**
```bash
# Using Homebrew (recommended)
brew install python

# Or download from python.org
# Verify installation
python3 --version
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install python3 python3-pip python3-venv
python3 --version
```

#### Git Installation

**Windows:**
1. Download Git from [git-scm.com](https://git-scm.com/download/win)
2. Run the installer with default settings
3. Verify installation: `git --version`

**macOS:**
```bash
# Using Homebrew
brew install git

# Verify installation
git --version
```

**Linux:**
```bash
sudo apt install git
git --version
```

## 🚀 Installation Steps

### Step 1: Clone the Repository

```bash
# Clone the repository
git clone https://github.com/actuallyrizzn/project1960.git

# Navigate to the project directory
cd project1960
```

### Step 2: Create Virtual Environment

**Windows:**
```bash
# Create virtual environment
python -m venv venv

# Activate virtual environment
venv\Scripts\activate
```

**macOS/Linux:**
```bash
# Create virtual environment
python3 -m venv venv

# Activate virtual environment
source venv/bin/activate
```

**Verify Activation:**
```bash
# You should see (venv) in your prompt
# Check Python location
which python  # Should point to venv directory
```

### Step 3: Install Dependencies

```bash
# Upgrade pip first
pip install --upgrade pip

# Install project dependencies
pip install -r requirements.txt
```

**Expected Output:**
```
Collecting requests==2.31.0
  Downloading requests-2.31.0-py3-none-any.whl (62 kB)
Collecting beautifulsoup4==4.12.3
  Downloading beautifulsoup4-4.12.3-py3-none-any.whl (147 kB)
...
Successfully installed requests-2.31.0 beautifulsoup4-4.12.3 ...
```

### Step 4: Configure Environment

```bash
# Copy environment template
cp env.example .env

# Edit the .env file with your API key
# Use your preferred text editor
```

**Windows (Notepad):**
```bash
notepad .env
```

**macOS/Linux (nano):**
```bash
nano .env
```

**macOS/Linux (vim):**
```bash
vim .env
```

**Required Configuration:**
```bash
# Add your Venice AI API key
VENICE_API_KEY=your_actual_api_key_here

# Optional: Customize other settings
DATABASE_NAME=doj_cases.db
FLASK_DEBUG=False
FLASK_HOST=0.0.0.0
FLASK_PORT=5000
```

### Step 5: Get Venice AI API Key

1. **Visit**: [https://api.venice.ai](https://api.venice.ai)
2. **Sign Up**: Create an account
3. **Get API Key**: Navigate to your account settings
4. **Copy Key**: Copy the API key to your `.env` file

**Important**: Keep your API key secure and never commit it to version control.

### Step 6: Verify Installation

```bash
# Test the installation
python -c "import flask, requests, beautifulsoup4; print('All dependencies installed successfully!')"

# Check if environment is configured
python -c "from utils.config import Config; Config.validate(); print('Configuration is valid!')"
```

## 🔧 Post-Installation Setup

### Initialize Database

```bash
# Set up database tables
python enrich_cases_modular.py --setup-only
```

### Collect Initial Data

```bash
# Scrape DOJ press releases
python scraper.py

# Verify some cases
python 1960-verify_modular.py --limit 10
```

### Start Web Interface

```bash
# Launch the web application
python app.py
```

Visit [http://localhost:5000](http://localhost:5000) to access the dashboard.

## 🐛 Troubleshooting

### Common Installation Issues

#### Python Version Issues

**Problem**: "Python 3.8+ required"
```bash
# Check Python version
python --version

# If using Python 2, try python3
python3 --version

# Install Python 3.8+ if needed
```

#### Virtual Environment Issues

**Problem**: "venv module not found"
```bash
# Install venv module
sudo apt install python3-venv  # Ubuntu/Debian
brew install python3-venv      # macOS
```

**Problem**: "Permission denied" on Windows
```bash
# Run PowerShell as Administrator
# Or use Command Prompt as Administrator
```

#### Dependency Installation Issues

**Problem**: "pip install fails"
```bash
# Upgrade pip
pip install --upgrade pip

# Clear pip cache
pip cache purge

# Try installing with --no-cache
pip install -r requirements.txt --no-cache-dir
```

**Problem**: "Microsoft Visual C++ required" (Windows)
1. Install Microsoft Visual C++ Build Tools
2. Or install pre-compiled wheels: `pip install --only-binary=all -r requirements.txt`

#### API Key Issues

**Problem**: "VENICE_API_KEY environment variable is not set!"
```bash
# Check if .env file exists
ls -la .env

# Verify API key is set
cat .env | grep VENICE_API_KEY

# Set manually (temporary)
export VENICE_API_KEY=your_api_key_here
```

#### Database Issues

**Problem**: "Database is locked"
```bash
# Check for running processes
ps aux | grep python

# Kill stuck processes
pkill -f "python.*enrich_cases"

# Or restart your terminal
```

### Platform-Specific Issues

#### Windows Issues

**Problem**: "python command not found"
1. Add Python to PATH during installation
2. Or use `py` instead of `python`
3. Or use full path: `C:\Python39\python.exe`

**Problem**: "PowerShell execution policy"
```powershell
# Run as Administrator
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

#### macOS Issues

**Problem**: "Permission denied" for pip
```bash
# Use --user flag
pip install --user -r requirements.txt

# Or use Homebrew Python
brew install python
```

**Problem**: "SSL certificate errors"
```bash
# Update certificates
pip install --upgrade certifi
```

#### Linux Issues

**Problem**: "Package not found"
```bash
# Update package lists
sudo apt update

# Install build dependencies
sudo apt install build-essential python3-dev
```

**Problem**: "Permission issues"
```bash
# Use virtual environment (recommended)
# Or install with --user flag
pip install --user -r requirements.txt
```

## 🔒 Security Considerations

### API Key Security

- **Never commit API keys** to version control
- **Use environment variables** for sensitive data
- **Rotate API keys** regularly
- **Monitor API usage** for unusual activity

### Database Security

- **Backup regularly** your database files
- **Use file permissions** to restrict access
- **Consider encryption** for sensitive data
- **Monitor access** to database files

### Network Security

- **Use HTTPS** in production
- **Configure firewall** rules appropriately
- **Limit network access** to necessary ports
- **Monitor network traffic** for anomalies

## 📊 Performance Optimization

### System Tuning

**Memory Optimization:**
```bash
# Monitor memory usage
htop
free -h

# Increase swap if needed
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
```

**Disk Optimization:**
```bash
# Check disk space
df -h

# Clean up temporary files
sudo apt autoremove  # Ubuntu/Debian
brew cleanup         # macOS
```

### Python Optimization

**Virtual Environment:**
```bash
# Always use virtual environment
source venv/bin/activate  # Linux/macOS
venv\Scripts\activate     # Windows
```

**Package Management:**
```bash
# Keep packages updated
pip list --outdated
pip install --upgrade package_name

# Clean up unused packages
pip autoremove
```

## 🔄 Updating the Installation

### Update Code

```bash
# Pull latest changes
git pull origin main

# Update dependencies
pip install -r requirements.txt --upgrade
```

### Update Database Schema

```bash
# Run schema migration
python migrate_schemas.py

# Check database status
python check_db.py
```

### Backup Before Updates

```bash
# Create backup
cp doj_cases.db doj_cases_backup_$(date +%Y%m%d).db

# Or use git for code backup
git stash
git pull
git stash pop
```

## 📚 Next Steps

After successful installation:

1. **Read the [Quick Start Guide](quick-start.md)** to get up and running
2. **Explore the [Web Interface Guide](web-interface.md)** to learn about the dashboard
3. **Review the [CLI Tools Guide](cli-tools.md)** for command-line usage
4. **Check the [Architecture Documentation](architecture.md)** for technical details

## 🆘 Getting Help

If you encounter issues:

1. **Check the [Troubleshooting Guide](troubleshooting.md)**
2. **Review the [FAQ](faq.md)** for common questions
3. **Search existing issues** on GitHub
4. **Create a new issue** with detailed information

---

*This installation guide should get you up and running with Project1960 quickly and efficiently.* 