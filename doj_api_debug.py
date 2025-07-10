import requests

DOJ_API_URL = "https://www.justice.gov/api/v1/press_releases.json"
params = {"pagesize": 50, "page": 1}
response = requests.get(DOJ_API_URL, params=params, timeout=30)
print(f"Status code: {response.status_code}")
data = response.json()
print(f"Keys in response: {list(data.keys())}")
print(f"Number of results in first page: {len(data.get('results', []))}")
if 'total' in data:
    print(f"Total results reported by API: {data['total']}")
else:
    print("No 'total' key in API response.")
print(f"Sample result: {data.get('results', [{}])[0]}") 