# Product Shipment Implementation Tasks

## Database & Backend
- [ ] Create `api/shared/shipment-migrate.php` — auto-migration for shipments tables
- [ ] Modify `config/database.php` — call shipment migration
- [ ] Create `api/farmer/farmer-shipment-action.php` — farmer shipment CRUD API
- [ ] Create `api/customer/customer-shipment-data.php` — customer shipment read API
- [ ] Modify `api/farmer/farmer-order-action.php` — auto-create shipment on confirm

## Frontend — Farmer Side
- [ ] Create `assets/css/farmer-shipment.css` — farmer shipment styles
- [ ] Create `pages/farmer/farmer-shipment.html` — farmer shipment management page

## Frontend — Customer Side
- [ ] Create `assets/css/customer-shipment.css` — customer shipment tracking styles
- [ ] Create `pages/customer/customer-shipment.html` — customer shipment tracking page

## Navigation & Integration
- [ ] Modify `pages/customer/customer-orders.html` — add Track button + shipped filter
- [ ] Modify `pages/customer/customer-account.html` — add Shipments sidebar link
- [ ] Modify `pages/farmer/farmer-orders.html` — add shipment column + manage link
