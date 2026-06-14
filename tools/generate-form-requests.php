<?php

declare(strict_types=1);

/**
 * One-off generator for API Form Request classes.
 * Run: php tools/generate-form-requests.php
 */
$basePath = dirname(__DIR__);

/** @var list<array{namespace: string, class: string, rules: string, uses?: list<string>}> */
$requests = [
    // Inventory — Suppliers
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Requests', 'class' => 'StoreSupplierRequest', 'rules' => <<<'RULES'
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Requests', 'class' => 'UpdateSupplierRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Requests', 'class' => 'IndexPurchaseOrderRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'status' => ['nullable', 'string', 'max:50'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Requests', 'class' => 'StorePurchaseOrderRequest', 'rules' => <<<'RULES'
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        RULES],

    // Inventory — Stock
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'IndexIngredientRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'StoreIngredientRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'in:kg,g,l,ml,piece'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'UpdateIngredientRequest', 'rules' => <<<'RULES'
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'unit' => ['sometimes', 'in:kg,g,l,ml,piece'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'LowStockIngredientRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'IndexStockMovementRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'ingredient_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:purchase,waste,adjustment'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'StoreStockMovementRequest', 'rules' => <<<'RULES'
            'ingredient_id' => ['required', 'integer'],
            'type' => ['required', 'in:purchase,waste,adjustment'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:in,out'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'IndexStockCountRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:50'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'StoreStockCountRequest', 'rules' => <<<'RULES'
            'branch_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'UpsertStockCountLineRequest', 'rules' => <<<'RULES'
            'ingredient_id' => ['required', 'integer'],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'IndexStockTransferRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Requests', 'class' => 'StoreStockTransferRequest', 'rules' => <<<'RULES'
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'ingredient_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string', 'max:255'],
        RULES],

    // Inventory — Recipes
    ['namespace' => 'App\\Modules\\Inventory\\Recipes\\Http\\Requests', 'class' => 'SyncRecipeRequest', 'rules' => <<<'RULES'
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        RULES],

    // POS — Orders
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Requests', 'class' => 'IndexOrderRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'status' => ['nullable', 'in:active,cooking,ready,completed,paid,cancelled'],
            'branch_id' => ['nullable', 'integer'],
            'table_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Requests', 'class' => 'StoreOrderRequest', 'rules' => <<<'RULES'
            'branch_id' => ['required', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
            'channel' => ['required', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
            'notes' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Requests', 'class' => 'UpdateOrderRequest', 'rules' => <<<'RULES'
            'notes' => ['nullable', 'string'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Requests', 'class' => 'UpdateOrderStatusRequest', 'rules' => <<<'RULES'
            'status' => ['required', 'in:active,cooking,ready,completed,paid,cancelled'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Requests', 'class' => 'StoreOrderItemRequest', 'rules' => <<<'RULES'
            'menu_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        RULES],

    // POS — Menu
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'IndexMenuItemRequest', 'rules' => <<<'RULES'
            'category_id' => ['nullable', 'integer'],
            'available_only' => ['nullable', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'StoreMenuItemRequest', 'rules' => <<<'RULES'
            'category_id' => ['required', 'integer'],
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'preparation_time' => ['integer', 'min:1', 'max:120'],
            'sort_order' => ['integer', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'UpdateMenuItemRequest', 'rules' => <<<'RULES'
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'preparation_time' => ['sometimes', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'UploadMenuItemPhotoRequest', 'rules' => <<<'RULES'
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'StoreMenuCategoryRequest', 'rules' => <<<'RULES'
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Requests', 'class' => 'UpdateMenuCategoryRequest', 'rules' => <<<'RULES'
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
        RULES],

    // POS — Tables
    ['namespace' => 'App\\Modules\\POS\\Tables\\Http\\Requests', 'class' => 'IndexTableRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:50'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Tables\\Http\\Requests', 'class' => 'StoreTableRequest', 'rules' => <<<'RULES'
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'capacity' => ['integer', 'min:1', 'max:50'],
            'position_x' => ['integer', 'min:0'],
            'position_y' => ['integer', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Tables\\Http\\Requests', 'class' => 'UpdateTableRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'position_x' => ['sometimes', 'integer', 'min:0'],
            'position_y' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Tables\\Http\\Requests', 'class' => 'UpdateTableStatusRequest', 'rules' => <<<'RULES'
            'status' => ['required', 'in:free,occupied,reserved,unavailable'],
        RULES],

    // POS — Billing
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Requests', 'class' => 'SettlePaymentRequest', 'rules' => <<<'RULES'
            'method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu,split'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'splits' => ['required_if:method,split', 'array', 'min:2'],
            'splits.*.method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.reference' => ['nullable', 'string', 'max:100'],
            'loyalty_points' => ['nullable', 'integer', 'min:1'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Requests', 'class' => 'RefundPaymentRequest', 'rules' => <<<'RULES'
            'reason' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Requests', 'class' => 'DailyReportRequest', 'rules' => <<<'RULES'
            'date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Requests', 'class' => 'CashSummaryReportRequest', 'rules' => <<<'RULES'
            'date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Requests', 'class' => 'TopItemsReportRequest', 'rules' => <<<'RULES'
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        RULES],

    // POS — Kitchen
    ['namespace' => 'App\\Modules\\POS\\Kitchen\\Http\\Requests', 'class' => 'KitchenQueueRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],

    // Delivery
    ['namespace' => 'App\\Modules\\Delivery\\Customers\\Http\\Requests', 'class' => 'IndexCustomerRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'phone' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\Customers\\Http\\Requests', 'class' => 'StoreCustomerRequest', 'rules' => <<<'RULES'
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:100'],
            'default_address' => ['nullable', 'string', 'max:500'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\Customers\\Http\\Requests', 'class' => 'UpdateCustomerRequest', 'rules' => <<<'RULES'
            'name' => ['nullable', 'string', 'max:100'],
            'default_address' => ['nullable', 'string', 'max:500'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\Riders\\Http\\Requests', 'class' => 'IndexRiderRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\Riders\\Http\\Requests', 'class' => 'AssignRiderRequest', 'rules' => <<<'RULES'
            'rider_id' => ['required', 'integer', 'exists:users,id'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\Riders\\Http\\Requests', 'class' => 'UpdateDeliveryStatusRequest', 'rules' => <<<'RULES'
            'status' => ['required', 'in:assigned,picked_up,en_route,delivered,cancelled'],
        RULES],
    ['namespace' => 'App\\Modules\\Delivery\\QRMenu\\Http\\Requests', 'class' => 'PlaceQRMenuOrderRequest', 'rules' => <<<'RULES'
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'table_label' => ['nullable', 'string', 'max:50'],
            'fulfillment_type' => ['nullable', 'in:takeaway,delivery'],
            'delivery_address' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:500'],
        RULES],

    // Intelligence
    ['namespace' => 'App\\Modules\\Intelligence\\Analytics\\Http\\Requests', 'class' => 'CompareAggregatorRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        RULES],
    ['namespace' => 'App\\Modules\\Intelligence\\Loyalty\\Http\\Requests', 'class' => 'RedeemLoyaltyRequest', 'rules' => <<<'RULES'
            'points' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Intelligence\\Marketing\\Http\\Requests', 'class' => 'BroadcastMarketingRequest', 'rules' => <<<'RULES'
            'template_name' => ['required', 'string', 'max:100'],
            'segment' => ['required', 'in:all,inactive_30d,high_spenders,recent_visitors'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Intelligence\\Reports\\Http\\Requests', 'class' => 'WeeklyAIReportRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'week_start' => ['nullable', 'date'],
            'narrative' => ['nullable', 'boolean'],
        RULES],

    // Platform
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'IndexAdminTenantRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'search' => ['nullable', 'string', 'max:100'],
        RULES],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'StoreAdminTenantRequest', 'rules' => <<<'RULES'
            'restaurant_name' => ['required', 'string', 'max:150'],
            'subdomain' => ['required', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['nullable', 'in:ar,en'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8', 'max:100'],
            'owner_phone' => ['nullable', 'string', 'max:20'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'branch_name_ar' => ['nullable', 'string', 'max:100'],
            'branch_address' => ['nullable', 'string', 'max:255'],
            'branch_phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
        RULES],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'UpdateAdminTenantRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:150'],
            'subdomain' => ['sometimes', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'UpdateAdminTenantPlanRequest', 'rules' => <<<'RULES'
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
        RULES],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'UpdateAdminTenantStatusRequest', 'rules' => <<<'RULES'
            'status' => ['required', 'in:active,trial,grace_period,suspended'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        RULES],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Requests', 'class' => 'UpdateAdminTenantFeaturesRequest', 'rules' => <<<'RULES'
            'feature_flags' => ['required', 'array'],
            'feature_flags.*' => ['string', 'max:50'],
        RULES],

    // Tenant
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'StoreBranchRequest', 'rules' => <<<'RULES'
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UpdateBranchRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'StoreStaffRequest', 'rules' => <<<'RULES'
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:manager,cashier,waiter,cook,rider'],
            'branch_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UpdateStaffRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'in:manager,cashier,waiter,cook,rider'],
            'branch_id' => ['sometimes', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'is_active' => ['sometimes', 'boolean'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'IndexStaffRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UpdateTenantSettingsRequest', 'rules' => <<<'RULES'
            'name' => ['sometimes', 'string', 'max:150'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:50'],
            'talabat_webhook_secret' => ['nullable', 'string', 'max:255'],
            'elmenus_webhook_secret' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UpdateETASettingsRequest', 'rules' => <<<'RULES'
            'eta_client_id' => ['nullable', 'string', 'max:100'],
            'eta_client_secret' => ['nullable', 'string', 'max:255'],
            'eta_taxpayer_id' => ['nullable', 'string', 'max:100'],
            'eta_branch_id' => ['nullable', 'string', 'max:20'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UploadETACertificateRequest', 'rules' => <<<'RULES'
            'certificate' => ['required', 'file', 'max:5120'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'UpgradeSubscriptionRequest', 'rules' => <<<'RULES'
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
            'gateway' => ['required', 'in:paymob,fawry'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'DowngradeSubscriptionRequest', 'rules' => <<<'RULES'
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Requests', 'class' => 'IndexAuditLogRequest', 'uses' => ['App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules'], 'rules' => <<<'RULES'
            'causer_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Staff\\Http\\Requests', 'class' => 'IndexStaffShiftRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Staff\\Http\\Requests', 'class' => 'ActiveStaffShiftRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Staff\\Http\\Requests', 'class' => 'ClockInStaffShiftRequest', 'rules' => <<<'RULES'
            'branch_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Staff\\Http\\Requests', 'class' => 'ClockOutStaffShiftRequest', 'rules' => <<<'RULES'
            'notes' => ['nullable', 'string', 'max:255'],
            'closing_cash_count' => ['nullable', 'numeric', 'min:0'],
        RULES],
    ['namespace' => 'App\\Modules\\Tenant\\Reports\\Http\\Requests', 'class' => 'CompareBranchReportRequest', 'rules' => <<<'RULES'
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        RULES],
];

foreach ($requests as $request) {
    $namespace = $request['namespace'];
    $class = $request['class'];
    $uses = $request['uses'] ?? [];
    $rules = trim($request['rules']);

    $relativePath = str_replace('App\\', 'app/', $namespace);
    $relativePath = str_replace('\\', '/', $relativePath);
    $dir = $basePath.'/'.$relativePath;
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $useLines = array_merge(
        ['App\\Shared\\Support\\Http\\Requests\\ApiFormRequest'],
        $uses,
    );
    $useBlock = implode("\n", array_map(fn (string $u): string => 'use '.$u.';', $useLines));

    $traitUses = '';
    $rulesMethodBody = "return [\n            {$rules}\n        ];";
    if (in_array('App\\Shared\\Support\\Http\\Requests\\Concerns\\HasPaginationRules', $uses, true)) {
        $traitUses = "\n    use HasPaginationRules;";
        $rulesMethodBody = "return array_merge([\n            {$rules}\n        ], \$this->paginationRules());";
    }

    $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$useBlock}

class {$class} extends ApiFormRequest
{{$traitUses}
    /** @return array<string, mixed> */
    public function rules(): array
    {
        {$rulesMethodBody}
    }
}

PHP;

    file_put_contents($dir.'/'.$class.'.php', $content);
    echo "Created {$class}\n";
}

echo 'Done. Created '.count($requests)." form requests.\n";
