# Quick Start Guide

Get Project1960 up and running in minutes! This guide will walk you through the essential steps to start analyzing DOJ press releases for 18 USC 1960 violations.

## 🚀 Prerequisites

- **Python 3.8+** installed on your system
- **Venice AI API key** (get one at [https://api.venice.ai](https://api.venice.ai))
- **Git** (for cloning the repository)

## ⚡ 5-Minute Setup

### 1. Clone the Repository

```bash
git clone https://github.com/actuallyrizzn/project1960.git
cd project1960
```

### 2. Install Dependencies

```bash
pip install -r requirements.txt
```

### 3. Configure Environment

```bash
# Copy the environment template
cp env.example .env

# Edit .env and add your Venice AI API key
# Replace 'your_actual_api_key_here' with your real API key
```

**Required Environment Variables:**
```bash
VENICE_API_KEY=your_actual_api_key_here
```

### 4. Run the Web Interface

```bash
python app.py
```

Visit [http://localhost:5000](http://localhost:5000) to see the dashboard!

## 📊 What You'll See

The dashboard will show:
- **Total Cases**: Number of DOJ press releases in the database
- **Verified 1960**: Cases confirmed to involve 18 USC 1960 violations
- **Enrichment Progress**: Data extraction status across different tables
- **Recent Activity**: Latest processing activity

## 🔍 Next Steps

### Explore Existing Data
- Click **"Browse Cases"** to see all press releases with filtering options
- Use the search and filter tools to find specific cases
- Click on any case ID to see detailed information

### Run Data Collection
If you want to collect fresh data:

```bash
# Scrape new DOJ press releases
python scraper.py

# Verify cases for 1960 violations (recommended)
python 1960-verify_modular.py --limit 10

# Enrich verified cases with detailed data
python enrich_cases_modular.py --table case_metadata --limit 5
```

### View Enrichment Progress
- Navigate to **"Enrichment Dashboard"** to see data extraction progress
- Click on case IDs in the activity log to view detailed case information
- Monitor progress across 8 different data tables

## 🎯 Key Features to Try

### 1. Case Filtering
- Filter by classification status (Yes/No/Unknown)
- Search for specific terms in titles and content
- Filter by 1960 mentions and cryptocurrency mentions

### 2. Data Enrichment
- View extracted case metadata (district, judge, case numbers)
- See participant information (defendants, prosecutors, agents)
- Explore charges, financial actions, and victim details

### 3. Dark Mode
- Toggle dark mode using the switch in the top navigation
- Your preference is saved automatically

## 🔧 Troubleshooting

### Common Issues

**"VENICE_API_KEY environment variable is not set!"**
- Make sure you've created the `.env` file and added your API key
- Check that the key is correct and has no extra spaces

**"No module named 'dirtyjson'"**
- Run `pip install -r requirements.txt` to install all dependencies

**Database is empty**
- Run `python scraper.py` to collect initial data
- Then run `python 1960-verify_modular.py` to classify cases

**Web interface won't start**
- Check that port 5000 is available
- Try `python app.py --host 127.0.0.1 --port 5001` to use a different port

## 📚 What's Next?

- **[Installation Guide](installation.md)** - Detailed setup instructions
- **[Web Interface Guide](web-interface.md)** - Complete dashboard walkthrough
- **[Command Line Tools](cli-tools.md)** - Using the enrichment and verification scripts
- **[Data Enrichment Guide](enrichment-guide.md)** - Understanding the AI-powered data extraction

## 🆘 Need Help?

- Check the **[Troubleshooting Guide](troubleshooting.md)** for common issues
- Review the **[FAQ](faq.md)** for answers to frequently asked questions
- Visit the **[GitHub Repository](https://github.com/actuallyrizzn/project1960)** for the latest updates

---

*Ready to start analyzing DOJ press releases? The dashboard is waiting for you at [http://localhost:5000](http://localhost:5000)!* 