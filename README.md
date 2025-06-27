# piprapay Payment Gateway for FOSSBilling

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

This is the official piprapay payment gateway module for FOSSBilling. It allows you to accept payments via piprapay in your FOSSBilling installation.

## Installation

### Step 1: Download the Module
Download the latest release of the piprapay payment gateway.

### Step 2: Install the Module
Upload the `piprapay` folder to your FOSSBilling installation's payment adapters directory: {FOSSBilling_Path}/library/Payment/Adapter/

### Step 3: Enable the Gateway
1. Log in to your FOSSBilling admin area
2. Navigate to `System > Payment Gateways`
3. Find "piprapay" in the list and click "Activate"
4. Configure your piprapay API credentials:
   - API Key (Get this from your piprapay dashboard)
   - API URL (Typically `https://pay.yourdomain.com` or sandbox URL)
   - Currency (BDT/USD)
