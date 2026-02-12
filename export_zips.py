import pandas as pd
import json
import os
import numpy as np

def extract_zips():
    data = {
        "cities": {},
        "barangays": {}
    }

    # Metro Manila
    try:
        mm_df = pd.read_excel('zips/MetroManilaZipCodes.xlsx', sheet_name='MM')
        # Clean and filter
        mm_df = mm_df.dropna(subset=['City', 'ZIP Code'])
        
        for _, row in mm_df.iterrows():
            city = str(row['City']).strip().upper()
            zip_code = str(int(row['ZIP Code'])) if isinstance(row['ZIP Code'], (int, float)) else str(row['ZIP Code']).strip()
            
            # Map City -> Zip (general)
            # Logic: If a city has multiple zips depending on location/barangay, 
            # we might just take one as default for the city table, or rely on barangay level.
            # But the requirement for Option 1 is City -> Zip. 
            # If a city appears multiple times, the last one overwrites. 
            # Ideally we pick the most common or 'main' one.
            # For simplicity, we overwrite. 
            data["cities"][city] = zip_code

            # Map Barangay -> Zip
            if 'Barangay' in row and pd.notna(row['Barangay']) and str(row['Barangay']).strip():
                brgy = str(row['Barangay']).strip().upper()
                key = f"{city}|{brgy}"
                data["barangays"][key] = zip_code
                
    except Exception as e:
        print(f"Error processing MetroManilaZipCodes.xlsx: {e}")

    # Provinces
    try:
        prov_df = pd.read_excel('zips/provinceZip.xlsx')
        prov_df = prov_df.dropna(subset=['Province', 'ZIP Code'])
        
        for _, row in prov_df.iterrows():
            # In province file, 'City' column might be named 'City' or imply Municipality
            # Let's inspect columns based on phZip.py
            # phZip.py uses: 'Province', 'ZIP Code', 'Barangay', and maybe 'City' isn't used there?
            # Let's check columns if 'City' exists.
            
            city = ""
            # Fix: The file has a column 'Barangay' which actually contains Municipality/City names
            # based on data inspection (Abra -> Bangued).
            if 'City' in row and pd.notna(row['City']):
                 city = str(row['City']).strip().upper()
            elif 'Municipality' in row and pd.notna(row['Municipality']):
                 city = str(row['Municipality']).strip().upper()
            elif 'Barangay' in row and pd.notna(row['Barangay']):
                 # Fallback: Assume 'Barangay' column holds the City/Municipality
                 # But safeguard: is there a verified Barangay column?
                 # If only 3 columns [Province, Barangay, ZIP Code], then 'Barangay' is likely City.
                 city = str(row['Barangay']).strip().upper()
            
            # If we used 'Barangay' column as city, then we have no Barangay info for this row.
            # Which implies the zip is for the whole city/municipality.
            
            zip_code = str(int(row['ZIP Code'])) if isinstance(row['ZIP Code'], (int, float)) else str(row['ZIP Code']).strip() # logic continued below
            
            zip_code = str(int(row['ZIP Code'])) if isinstance(row['ZIP Code'], (int, float)) else str(row['ZIP Code']).strip()

            if city:
                data["cities"][city] = zip_code
                
                if 'Barangay' in row and pd.notna(row['Barangay']) and str(row['Barangay']).strip():
                    brgy = str(row['Barangay']).strip().upper()
                    key = f"{city}|{brgy}"
                    data["barangays"][key] = zip_code

    except Exception as e:
        print(f"Error processing provinceZip.xlsx: {e}")
        # Let's attempt to read columns to debug if key error
        try:
             df_debug = pd.read_excel('zips/provinceZip.xlsx', nrows=0)
             print(f"Columns in provinceZip.xlsx: {df_debug.columns.tolist()}")
        except:
            pass

    # Special Case: Cabanatuan
    # Ensure Cabanatuan City is 3100 if not found
    if "CABANATUAN CITY" not in data["cities"]:
        data["cities"]["CABANATUAN CITY"] = "3100"

    with open('zips.json', 'w') as f:
        json.dump(data, f, indent=2)
    
    print("Done. zips.json created.")

if __name__ == "__main__":
    extract_zips()
