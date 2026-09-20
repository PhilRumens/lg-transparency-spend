import os
import re
import requests
import pandas as pd

# 1. Official Data.gov.uk CKAN production engine endpoint
BASE_URL = "https://data.gov.uk/api/action/package_search"
OUTPUT_DIR = "./raw_council_spend"
TECH_SPEND_FILE = "./tech_spend_dashboard_data.csv"
os.makedirs(OUTPUT_DIR, exist_ok=True)

# 2. Strict technology mapping patterns
TECH_KEYWORDS = [
    r"ict", r"software", r"hardware", r"telecom", r"broadband", r"cloud", 
    r"hosting", r"computing", r"it services", r"server", r"network", 
    r"licence", r"license", r"digital", r"cyber", r"data centre"
]
tech_regex = re.compile("|".join(TECH_KEYWORDS), re.IGNORECASE)

def fetch_spend_datasets(limit=100):
    """Queries the live data.gov.uk directory registry for transparency files."""
    print(f"Connecting to production data endpoint: {BASE_URL}")
    
    # Target packages explicitly titled or metadata-tagged around council spending over £500
    params = {
        "q": 'title:"spending over £500" OR title:"spend over £500"',
        "rows": limit,
        "start": 0
    }
    
    try:
        headers = {"User-Agent": "CouncilTechDashboardPipeline/1.0"}
        response = requests.get(BASE_URL, params=params, headers=headers, timeout=20)
        response.raise_for_status()
        
        data = response.json()
        if not data.get("success", False):
            print("API returned an internal error state.")
            return []
            
        return data.get("result", {}).get("results", [])
    except requests.exceptions.RequestException as e:
        print(f"Connection failed entirely: {e}")
        return []

def process_and_filter_csv(url, council_name):
    """Downloads individual council CSV logs and filters down to tech metrics."""
    try:
        # Stream csv using latin1 to safely handle UK pound signs (£) without crashing
        df = pd.read_csv(url, encoding="latin1", on_bad_lines="skip")
        
        # Standardise header cases for uniform processing
        df.columns = [str(col).strip().lower() for col in df.columns]
        
        # Dynamic property finder maps fields across mismatched council sheets
        supplier_col = next((c for c in df.columns if "supplier" in c or "vendor" in c), None)
        expense_col = next((c for c in df.columns if "expense" in c or "desc" in c or "category" in c or "service" in c), None)
        amount_col = next((c for c in df.columns if "amount" in c or "value" in c or "net" in c), None)
        date_col = next((c for c in df.columns if "date" in c), None)
        
        if not supplier_col or not amount_col:
            return None 
            
        # Structure unified fields for your analytical tools
        df_clean = pd.DataFrame()
        df_clean["council"] = [council_name] * len(df)
        df_clean["date"] = df[date_col] if date_col else "Unknown"
        df_clean["supplier"] = df[supplier_col].astype(str)
        df_clean["amount"] = pd.to_numeric(df[amount_col].astype(str).str.replace(r"[^\d\.]", "", regex=True), errors="coerce")
        df_clean["description"] = df[expense_col].astype(str) if expense_col else "Not Provided"
        
        # Match keywords against transaction labels and company listings
        is_tech = df_clean["description"].str.contains(tech_regex, na=False) | df_clean["supplier"].str.contains(tech_regex, na=False)
        tech_df = df_clean[is_tech].dropna(subset=["amount"])
        
        return tech_df
        
    except Exception as e:
        # Ignore dead links or inaccessible remote server timeouts gracefully
        return None

# --- Main Runtime Execution ---
if __name__ == "__main__":
    # Pulling 50 metadata datasets to start with
    datasets = fetch_spend_datasets(limit=50)
    master_tech_records = []

    print(f"Discovered {len(datasets)} active metadata indexes. Processing CSV files...")

    for package in datasets:
        council_name = package.get("organization", {}).get("title", "Unknown Council")
        
        for resource in package.get("resources", []):
            if resource.get("format", "").lower() == "csv":
                file_url = resource.get("url")
                print(f"Scraping: {council_name} -> {resource.get('name')}")
                
                filtered_data = process_and_filter_csv(file_url, council_name)
                if filtered_data is not None and not filtered_data.empty:
                    master_tech_records.append(filtered_data)
                    print(f" -> Found {len(filtered_data)} tech invoices.")

    # Concat entries into a clean dashboard-ready master table
    if master_tech_records:
        final_dashboard_df = pd.concat(master_tech_records, ignore_index=True)
        final_dashboard_df.to_csv(TECH_SPEND_FILE, index=False)
        print(f"\nPipeline active! Master data exported to: {TECH_SPEND_FILE}")
    else:
        print("\nNo records were processed. Verify network connection rules.")
