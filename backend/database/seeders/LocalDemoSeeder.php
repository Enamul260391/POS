<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryBatch;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Category\CategoryService;
use App\Services\Customer\CustomerService;
use App\Services\Expense\ExpenseService;
use App\Services\ExpenseCategory\ExpenseCategoryService;
use App\Services\Payment\PaymentService;
use App\Services\Product\ProductService;
use App\Services\Purchase\PurchaseService;
use App\Services\Sale\SaleService;
use App\Services\SaleReturn\SaleReturnService;
use App\Services\Supplier\SupplierService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * LOCAL DEVELOPMENT ONLY — a realistic, rerunnable POS dataset.
 *
 * Run it explicitly; it is deliberately **not** registered in
 * {@see DatabaseSeeder::run()}, so `php artisan db:seed` never touches it:
 *
 *     php artisan db:seed --class=LocalDemoSeeder
 *
 * ## Why it refuses to run outside `local`
 *
 * The guard in {@see run()} is a runtime environment check, not a comment:
 * outside `local` the seeder writes nothing at all and reports the refusal.
 * Every record it would create is fake master data behind a publicly known
 * password, which has no business existing near a real installation. It follows
 * the same convention as the existing {@see DevUserSeeder}.
 *
 * ## It reuses the application, it does not reimplement it
 *
 * Nothing here writes a document directly. Sales go through
 * {@see SaleService}, purchases through {@see PurchaseService}, returns through
 * {@see SaleReturnService}, expenses through {@see ExpenseService}, the drawer
 * through {@see CashRegisterService} and stock through the inventory ledger via
 * {@see ProductService}. That is the point: the dataset must exercise the real
 * flows — server-side totals, `sale_items.unit_cost` snapshots, the
 * `stock_movements` ledger, the customer ledger, cash-register projections — so
 * the reports have something honest to read. Direct `DB::table()` inserts would
 * look right in a table viewer and be wrong everywhere else.
 *
 * ## Idempotency
 *
 * Every record carries a stable identifier — the business name, a user email, a
 * `DEMO-` SKU prefix, a `DEMO-PO-####` purchase invoice, a `local-demo:` tag in
 * the sale notes, a `DEMO-EXP-##` expense reference, a `DEMO-PAY-##` payment
 * reference. A rerun finds the existing row and reuses or refreshes it rather
 * than creating a second one. Nothing is deleted or truncated: the seeder never
 * wipes a developer's data, and running it twice leaves one dataset.
 */
class LocalDemoSeeder extends Seeder
{
    /** The one business this dataset belongs to. */
    private const BUSINESS_NAME = 'Local Demo Business';

    /** Prefixed onto every seeded SKU so demo stock cannot collide with real data. */
    private const SKU_PREFIX = 'DEMO-';

    /** Tag written into `sales.notes` and `sale_returns.reason`; also the rerun key. */
    private const SALE_TAG = 'local-demo:';

    /** The primary local login. */
    private const ADMIN_EMAIL = 'admin@example.com';

    /**
     * Deliberately weak — a local fixture password, and the seeder that owns it
     * refuses to run outside `local`. Hashing is Laravel's own: the value is
     * written through the model's `password => hashed` cast.
     */
    private const DEMO_PASSWORD = 'password';

    /**
     * Build the dataset.
     */
    public function run(): void
    {
        // ---- Local-only guard -------------------------------------------
        if (! app()->environment('local')) {
            $this->command?->error(
                'LocalDemoSeeder is for local development only and refused to run in the ['
                .app()->environment().'] environment.'
            );

            return;
        }

        $business = $this->business();
        $this->command?->info("Business: {$business->name} (#{$business->getKey()})");

        // Roles and permissions must exist for this business before any user is
        // attached to one. Re-running the existing seeder is safe: every insert
        // in it is conditional on the row being absent.
        $this->call(RolesAndPermissionsSeeder::class);

        $staff = $this->staff($business);
        $admin = $staff['admin'];

        $categories = $this->categories($business);
        $products = $this->products($business, $admin, $categories);
        $customers = $this->customers($business);
        $suppliers = $this->suppliers($business);
        $expenseCategories = $this->expenseCategories($business);

        // The drawer is opened before any document is written, so the cash
        // projections those flows post have somewhere real to land.
        $this->cashRegister($business, $admin);

        $this->purchases($business, $admin, $suppliers, $products);
        $sales = $this->sales($business, $admin, $customers, $products);

        // Returns are created before the later payments on purpose. A return is
        // settled against what the sale had actually collected *at that moment*,
        // so doing it in this order is what produces both settlement paths: the
        // cash sale refunds from the drawer, while the sale that was never paid
        // cannot be refunded by any method and is relieved on the customer's
        // account instead. Reversing the order would let the later payment
        // cover the refund and hide the ledger-relief path.
        $this->saleReturns($business, $admin, $sales, $products);
        $this->extraPayments($business, $admin, $sales);
        $this->expenses($business, $admin, $expenseCategories);

        $this->command?->info('Local demo dataset ready.');
        $this->command?->info('Login: '.self::ADMIN_EMAIL.' / '.self::DEMO_PASSWORD);
    }

    // -----------------------------------------------------------------
    // Business
    // -----------------------------------------------------------------

    /**
     * The demo business, created once and refreshed on every rerun.
     *
     * Keyed on the name, which is the same stable identifier the summary tells
     * the developer to look for, so `firstOrNew` never produces a second
     * business. The update keeps the demo profile (currency, timezone, contact
     * fields, active status) in step with this file.
     */
    private function business(): Business
    {
        $business = Business::query()->firstOrNew(['name' => self::BUSINESS_NAME]);

        $business->fill([
            'phone' => '+8801700000001',
            'email' => 'hello@local-demo.test',
            'address' => '12 Demo Road, Dhanmondi, Dhaka 1209',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'status' => Business::STATUS_ACTIVE,
        ])->save();

        return $business;
    }

    // -----------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------

    /**
     * A small staff set, all inside the demo business, each on one seeded role.
     *
     * The admin is the primary login and the only account the summary hands
     * out. Role assignment is an ordinary `sync` on the many-to-many relation,
     * so a rerun cannot accumulate roles. The roles themselves come from
     * {@see RolesAndPermissionsSeeder}: this file never creates a role and never
     * writes a `permission_role` row, so the admin ends up with exactly the
     * permission matrix the application already defines — nothing is
     * hard-coded and nothing is widened.
     *
     * The password is re-asserted on every run (through the `hashed` cast), so a
     * developer who changed it locally gets back to the documented credentials
     * by rerunning the seeder.
     *
     * @return array<string, User>
     */
    private function staff(Business $business): array
    {
        $definitions = [
            'admin' => [
                'email' => self::ADMIN_EMAIL,
                'name' => 'Local Admin',
                'phone' => '+8801700000011',
                'branch' => 'Head Office',
                'notes' => 'Local development admin. Never create this account outside local.',
            ],
            'manager' => [
                'email' => 'manager@example.com',
                'name' => 'Local Manager',
                'phone' => '+8801700000012',
                'branch' => 'Head Office',
                'notes' => 'Local development manager.',
            ],
            'salesman' => [
                'email' => 'salesman@example.com',
                'name' => 'Local Salesman',
                'phone' => '+8801700000013',
                'branch' => 'Showroom',
                'notes' => 'Local development salesperson.',
            ],
            'accountant' => [
                'email' => 'accountant@example.com',
                'name' => 'Local Accountant',
                'phone' => '+8801700000014',
                'branch' => 'Accounts',
                'notes' => 'Local development accountant.',
            ],
        ];

        $users = [];

        foreach ($definitions as $slug => $definition) {
            $user = User::query()->firstOrNew(['email' => $definition['email']]);

            $user->fill([
                'business_id' => $business->getKey(),
                'name' => $definition['name'],
                'phone' => $definition['phone'],
                'branch' => $definition['branch'],
                'notes' => $definition['notes'],
                'status' => User::STATUS_ACTIVE,
                // Plain value in, hashed value stored: `User::$casts` maps
                // `password` to `hashed`, so this is Laravel's normal mechanism
                // and nothing here weakens or bypasses authentication.
                'password' => self::DEMO_PASSWORD,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $role = Role::query()
                ->forBusiness((int) $business->getKey())
                ->where('slug', $slug)
                ->first();

            if ($role === null) {
                $this->command?->warn("Role [{$slug}] is missing for the demo business; user not granted it.");

                continue;
            }

            $user->roles()->sync([$role->getKey()]);
            $users[$slug] = $user;
        }

        return $users;
    }

    // -----------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------

    /**
     * Four product categories, created through the real category service.
     *
     * Keyed on `(business_id, name)` — the same pair the schema's unique index
     * is built on — so a rerun reuses the row rather than colliding with it.
     *
     * @return array<string, Category>
     */
    private function categories(Business $business): array
    {
        $service = app(CategoryService::class);

        $definitions = [
            'electronics' => ['Electronics', 'Phones, audio, chargers and computer peripherals.'],
            'accessories' => ['Accessories', 'Bags, cases and everyday carry items.'],
            'office' => ['Office Supplies', 'Paper, pens and desk essentials.'],
            'grocery' => ['Grocery', 'Everyday packaged grocery lines.'],
        ];

        $categories = [];
        foreach ($definitions as $key => [$name, $description]) {
            $existing = Category::query()
                ->forBusiness((int) $business->getKey())
                ->where('name', $name)
                ->first();

            $categories[$key] = $existing ?? $service->create((int) $business->getKey(), [
                'name' => $name,
                'description' => $description,
                'status' => Category::STATUS_ACTIVE,
            ]);
        }

        return $categories;
    }

    /**
     * Fourteen products across the four categories.
     *
     * A product is master data: it carries no stock, and creating one writes
     * no stock movement and no batch. Every unit in the demo shop therefore
     * arrives through the **purchases** below, which record the real purchase
     * date and the known purchase cost and create the batch the lot is tracked
     * in — the same path the application itself uses.
     *
     * `low_stock_threshold` is chosen against each product's *final* stock
     * (purchases - sales + returns) so that, once the whole dataset is in
     * place, three products read as low stock and one as out of stock in the
     * products list, the dashboard and the inventory report.
     *
     * @param  array<string, Category>  $categories
     * @return array<string, Product>
     */
    private function products(Business $business, User $admin, array $categories): array
    {
        $service = app(ProductService::class);

        // key => [category, name, low-stock threshold]
        $definitions = [
            'mouse' => ['electronics', 'Wireless Mouse', 10],
            'cable' => ['electronics', 'USB-C Cable 1m', 20],
            'earbuds' => ['electronics', 'Bluetooth Earbuds', 5],
            'charger' => ['electronics', '20W USB Charger', 15],
            'keyboard' => ['electronics', 'Mechanical Keyboard', 5],
            'backpack' => ['accessories', 'Laptop Backpack', 5],
            'case' => ['accessories', 'Phone Case', 15],
            'cleaner' => ['accessories', 'Screen Cleaner Kit', 30],
            'paper' => ['office', 'A4 Paper Ream', 10],
            'pen' => ['office', 'Ballpoint Pen Box', 25],
            'notes' => ['office', 'Sticky Notes Pack', 25],
            'organiser' => ['office', 'Desk Organiser', 5],
            'noodles' => ['grocery', 'Instant Noodles 12pk', 20],
            'tea' => ['grocery', 'Green Tea 25sachet', 10],
        ];

        $products = [];

        foreach ($definitions as $key => [$categoryKey, $name, $threshold]) {
            $sku = self::SKU_PREFIX.strtoupper($key);

            $existing = Product::query()
                ->forBusiness((int) $business->getKey())
                ->where('sku', $sku)
                ->first();

            if ($existing !== null) {
                // Reuse, and keep the master data in step with this file. Stock is
                // deliberately NOT touched: it belongs to the ledger, and the
                // purchases below are its only source. Re-posting it here would
                // silently double the balance.
                $existing->fill([
                    'category_id' => $categories[$categoryKey]->getKey(),
                    'name' => $name,
                    'low_stock_threshold' => $threshold,
                    'status' => Product::STATUS_ACTIVE,
                ])->save();

                $products[$key] = $existing;

                continue;
            }

            $products[$key] = $service->create((int) $business->getKey(), (int) $admin->getKey(), [
                'category_id' => $categories[$categoryKey]->getKey(),
                'name' => $name,
                'sku' => $sku,
                'barcode' => '890'.str_pad((string) (count($products) + 1), 10, '0', STR_PAD_LEFT),
                'unit' => 'pcs',
                'low_stock_threshold' => $threshold,
                'status' => Product::STATUS_ACTIVE,
            ]);
        }

        return $products;
    }

    // -----------------------------------------------------------------
    // Customers & suppliers
    // -----------------------------------------------------------------

    /**
     * Five customers, created through the customer service.
     *
     * The service also posts `opening_balance` into the customer ledger, so two
     * of these start with money already owed and the rest start clear. Balances
     * are never written by hand anywhere: what a customer owes is the sum of the
     * ledger rows, exactly as the application computes it, so every receivable
     * below arrives through the real flow.
     *
     * @return array<int, Customer>
     */
    private function customers(Business $business): array
    {
        $service = app(CustomerService::class);

        $definitions = [
            ['Ayesha Rahman', '+8801811000001', 'ayesha@example.test', 'House 7, Road 3, Dhanmondi, Dhaka', '0.00'],
            ['Imran Hossain', '+8801811000002', 'imran@example.test', 'Flat 4B, Mirpur DOHS, Dhaka', '500.00'],
            ['Nusrat Jahan', '+8801811000003', 'nusrat@example.test', 'House 21, Kazipara, Dhaka', '0.00'],
            ['Rafiq Ahmed', '+8801811000004', 'rafiq@example.test', 'Plot 9, Bashundhara, Dhaka', '1200.00'],
            ['Sadia Islam', '+8801811000005', 'sadia@example.test', 'House 55, Uttara, Dhaka', '0.00'],
        ];

        $customers = [];

        foreach ($definitions as [$name, $phone, $email, $address, $opening]) {
            $existing = Customer::query()
                ->forBusiness((int) $business->getKey())
                ->where('phone', $phone)
                ->first();

            if ($existing !== null) {
                $customers[] = $existing;

                continue;
            }

            $customers[] = $service->create((int) $business->getKey(), [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'opening_balance' => $opening,
                'status' => Customer::STATUS_ACTIVE,
            ]);
        }

        return $customers;
    }

    /**
     * Three suppliers, created through the supplier service.
     *
     * @return array<int, Supplier>
     */
    private function suppliers(Business $business): array
    {
        $service = app(SupplierService::class);

        $definitions = [
            ['TechSource Distribution', '+8801911000001', 'orders@techsource.test', 'Level 4, Elephant Road, Dhaka'],
            ['OfficeMart Supplies', '+8801911000002', 'sales@officemart.test', 'Shop 12, Newmarket, Dhaka'],
            ['FreshGro Foods', '+8801911000003', 'supply@freshgro.test', 'Warehouse 2, Tongi, Dhaka'],
        ];

        $suppliers = [];

        foreach ($definitions as [$name, $phone, $email, $address]) {
            $existing = Supplier::query()
                ->forBusiness((int) $business->getKey())
                ->where('name', $name)
                ->first();

            if ($existing !== null) {
                $suppliers[] = $existing;

                continue;
            }

            $suppliers[] = $service->create((int) $business->getKey(), [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'status' => Supplier::STATUS_ACTIVE,
            ]);
        }

        return $suppliers;
    }

    // -----------------------------------------------------------------
    // Expense categories
    // -----------------------------------------------------------------

    /**
     * The expense categories the expense documents below refer to.
     *
     * @return array<string, ExpenseCategory>
     */
    private function expenseCategories(Business $business): array
    {
        $service = app(ExpenseCategoryService::class);

        $definitions = [
            'rent' => ['Rent', 'Shop and warehouse rent.'],
            'utilities' => ['Utilities', 'Electricity, water and gas.'],
            'transport' => ['Transport', 'Delivery and staff travel.'],
            'office' => ['Office Expense', 'Stationery and general office costs.'],
        ];

        $categories = [];
        foreach ($definitions as $key => [$name, $description]) {
            $existing = ExpenseCategory::query()
                ->forBusiness((int) $business->getKey())
                ->where('name', $name)
                ->first();

            $categories[$key] = $existing ?? $service->create((int) $business->getKey(), [
                'name' => $name,
                'description' => $description,
                'status' => ExpenseCategory::STATUS_ACTIVE,
            ]);
        }

        return $categories;
    }

    // -----------------------------------------------------------------
    // Cash register
    // -----------------------------------------------------------------

    /**
     * Open the admin's drawer, once.
     *
     * A register in this application belongs to a single user, and every cash
     * sale, cash expense and cash refund projects itself onto whichever drawer
     * that user currently has open. It is therefore opened here — through the
     * real service, which also writes the `opening` marker row — before any
     * document is created, so those projections land somewhere real and the
     * admin can see a live drawer balance.
     *
     * No `sale`, `expense` or `refund` movement is ever written by hand:
     * automatic rows come from the flows that caused them, and only a genuine
     * `cash_in` / `cash_out` — which this dataset does not need — would be
     * manual.
     */
    private function cashRegister(Business $business, User $admin): void
    {
        $service = app(CashRegisterService::class);

        if ($service->currentOrNull((int) $business->getKey(), (int) $admin->getKey()) !== null) {
            return;
        }

        $service->open((int) $business->getKey(), (int) $admin->getKey(), [
            'opening_balance' => '5000.00',
            'notes' => 'Local demo drawer opened by LocalDemoSeeder.',
        ]);

        $this->command?->info('Opened a demo cash register for the local admin.');
    }

    // -----------------------------------------------------------------
    // Purchases
    // -----------------------------------------------------------------

    /**
     * Four purchases across all three suppliers, dated over the last five weeks.
     *
     * Each goes through {@see PurchaseService}, which is also what writes the
     * line `unit_cost` the profit report later reads as historical COGS, and
     * which appends the stock-in rows to the inventory ledger. `paid_amount` is
     * varied so the purchase summary shows paid, partial and pending documents.
     *
     * This project has no separate purchase-payment flow: the purchase header
     * carries `paid_amount` / `due_amount` and the service keeps them in step,
     * so no `payments` rows are invented for purchases here.
     *
     * @param  array<int, Supplier>  $suppliers
     * @param  array<string, Product>  $products
     */
    private function purchases(Business $business, User $admin, array $suppliers, array $products): void
    {
        $service = app(PurchaseService::class);

        // [supplier index, days ago, settlement, lines[product => [qty, unit cost, selling price]]]
        //
        // The selling price is the price the lot was bought at, and it becomes
        // the batch's own price — the price the POS sells that lot at. It is
        // stated per line because a lot may legitimately differ from the
        // product's master price: the screen cleaner was bought on a deal at
        // 140.00 while the product itself is listed at 150.00.
        $definitions = [
            [0, 35, 'paid', [
                'mouse' => [30, '240.00', '400.00'],
                'cable' => [60, '115.00', '200.00'],
                'case' => [50, '145.00', '300.00'],
            ]],
            [1, 20, 'partial', [
                'paper' => [40, '370.00', '550.00'],
                'pen' => [100, '85.00', '150.00'],
                'organiser' => [10, '340.00', '600.00'],
                'noodles' => [60, '290.00', '420.00'],
            ]],
            [2, 12, 'paid', [
                'earbuds' => [20, '1150.00', '1800.00'],
                'backpack' => [15, '880.00', '1400.00'],
                'tea' => [40, '175.00', '260.00'],
            ]],
            [0, 5, 'pending', [
                'charger' => [10, '440.00', '700.00'],
                'cleaner' => [20, '75.00', '140.00'],
                'notes' => [20, '55.00', '110.00'],
            ]],
        ];

        foreach ($definitions as $index => [$supplierIndex, $daysAgo, $settlement, $lines]) {
            // The invoice number is both a realistic reference and the rerun key.
            $invoiceNumber = 'DEMO-PO-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            $exists = Purchase::query()
                ->forBusiness((int) $business->getKey())
                ->where('invoice_number', $invoiceNumber)
                ->exists();

            if ($exists) {
                continue;
            }

            $items = [];
            $subtotal = '0.00';

            foreach ($lines as $productKey => [$quantity, $unitCost, $sellingPrice]) {
                $items[] = [
                    'product_id' => (int) $products[$productKey]->getKey(),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'selling_price' => $sellingPrice,
                ];
                $subtotal = Money::add($subtotal, Money::multiply((string) $quantity, $unitCost));
            }

            $paidAmount = match ($settlement) {
                'paid' => $subtotal,
                'partial' => Money::multiply($subtotal, '0.6'),
                default => '0.00',
            };

            $service->create((int) $business->getKey(), (int) $admin->getKey(), [
                'supplier_id' => (int) $suppliers[$supplierIndex]->getKey(),
                'invoice_number' => $invoiceNumber,
                'purchase_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                'discount' => '0.00',
                'tax' => '0.00',
                'paid_amount' => $paidAmount,
                'notes' => 'Demo purchase '.$invoiceNumber.'.',
                'items' => $items,
            ]);
        }

        $this->command?->info('Purchases ready.');
    }

    // -----------------------------------------------------------------
    // Sales
    // -----------------------------------------------------------------

    /**
     * Fourteen sales spread across today, yesterday, the past week and the
     * previous month, so every date filter in the Reports module has something
     * to narrow and the daily/monthly series have real shape.
     *
     * Each sale is written through {@see SaleService}, which is what produces
     * the server-side totals, the `sale_items.unit_cost` snapshots the profit
     * report reads, the `stock_movements` stock-out rows, the opening payment
     * and the customer-ledger receivable for anything unpaid. `settlement`
     * controls how much is taken at the counter:
     *
     *   - `full`    — settled at checkout, so no receivable is invented;
     *   - `partial` — settled in part; the remainder becomes a ledger receivable;
     *   - `none`    — wholly on account.
     *
     * A null customer index is a walk-in basket, which the schema allows.
     *
     * @param  array<int, Customer>  $customers
     * @param  array<string, Product>  $products
     * @return array<string, Sale> keyed by demo sale key, for the steps below
     */
    private function sales(Business $business, User $admin, array $customers, array $products): array
    {
        $service = app(SaleService::class);

        // [days ago, customer index|null, method, settlement, discount, tax, note,
        //  lines[product => [qty, unit price override|null]]]
        $definitions = $this->saleDefinitions();

        $sales = [];
        $index = 0;

        foreach ($definitions as [$daysAgo, $customerIndex, $method, $settlement, $discount, $tax, $note, $lines]) {
            $index++;
            $key = 'sale-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $existing = $this->existingSale($business, $key);
            if ($existing !== null) {
                $sales[$key] = $existing;

                continue;
            }

            $items = [];
            $subtotal = '0.00';

            foreach ($lines as $productKey => [$quantity, $unitPrice]) {
                // Products no longer carry a price: the lot's resale price lives
                // on its inventory batch (the purchase table), so fall back to
                // that batch price — the same figure the price-options endpoint
                // would confirm server-side.
                $price = $unitPrice ?? $this->batchSellingPrice($products[$productKey]);

                $items[] = [
                    'product_id' => (int) $products[$productKey]->getKey(),
                    'quantity' => $quantity,
                    'unit_price' => $price,
                ];
                $subtotal = Money::add($subtotal, Money::multiply((string) $quantity, $price));
            }

            $grandTotal = Money::add(Money::subtract($subtotal, $discount), $tax);

            $paidAmount = match ($settlement) {
                'full' => $grandTotal,
                'partial' => Money::multiply($grandTotal, '0.5'),
                default => '0.00',
            };

            $sales[$key] = $service->create(
                (int) $business->getKey(),
                (int) $admin->getKey(),
                [
                    'customer_id' => $customerIndex === null ? null : (int) $customers[$customerIndex]->getKey(),
                    'sale_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                    'items' => $items,
                    'discount' => $discount,
                    'tax' => $tax,
                    'paid_amount' => $paidAmount,
                    'payment_method' => $method,
                    // The tag doubles as the idempotency key: it lives in a real
                    // column of the sale, so a rerun finds this exact document
                    // instead of creating a second basket with the same lines.
                    'notes' => '['.self::SALE_TAG.$key.'] '.$note,
                ]
            );
        }

        $this->command?->info('Sales ready.');

        return $sales;
    }

    /**
     * The sales fixture.
     *
     * Rows: [days ago, customer index|null, method, settlement, discount, tax,
     * note, lines[product => [qty, unit price override|null]]].
     *
     * The unit price is `null` for every line except the screen cleaner, whose
     * lot was bought at 140.00 and is therefore sold at 140.00 — the batch's own
     * price, which is the only price a sale may carry. A line may not name a
     * price the lot does not have: the API confirms the price server-side and
     * refuses a mismatch rather than silently switching it.
     *
     * Distribution is deliberate: five today, three yesterday, three across the
     * rest of the week, two eighteen/thirty-two days back so the previous month
     * is represented. Every customer appears at least once, which is what makes
     * the customer ranking meaningful.
     *
     * @return array<int, array<int, mixed>>
     */
    private function saleDefinitions(): array
    {
        return [
            [0, null, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Walk-in: mouse and cable.', [
                'mouse' => [2, null], 'cable' => [1, null],
            ]],
            [0, 0, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Counter sale: paper and pens.', [
                'paper' => [1, null], 'pen' => [2, null],
            ]],
            [0, 1, Payment::PAYMENT_METHOD_CARD, 'full', '20.00', '0.00', 'Card sale with a small discount.', [
                'case' => [1, null], 'cleaner' => [2, '140.00'],
            ]],
            [0, 2, Payment::PAYMENT_METHOD_CASH, 'partial', '0.00', '0.00', 'Partly settled at the counter.', [
                'earbuds' => [1, null], 'charger' => [1, null],
            ]],
            [0, null, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Walk-in grocery basket.', [
                'noodles' => [3, null],
            ]],
            [1, 0, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Yesterday: bag and organiser.', [
                'backpack' => [1, null], 'organiser' => [1, null],
            ]],
            [1, 3, Payment::PAYMENT_METHOD_BANK, 'full', '0.00', '0.00', 'Yesterday: bank transfer.', [
                'mouse' => [1, null], 'cable' => [2, null],
            ]],
            [1, null, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Walk-in grocery basket.', [
                'tea' => [2, null], 'noodles' => [1, null],
            ]],
            [2, 1, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Two days ago: stationery.', [
                'pen' => [3, null], 'notes' => [2, null],
            ]],
            [3, 2, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Three days ago: counter sale.', [
                'cable' => [3, null], 'case' => [2, null],
            ]],
            [4, 3, Payment::PAYMENT_METHOD_CASH, 'none', '0.00', '0.00', 'On account, to be settled later.', [
                'backpack' => [1, null], 'paper' => [2, null],
            ]],
            [5, 4, Payment::PAYMENT_METHOD_CHEQUE, 'full', '0.00', '0.00', 'Five days ago: paid by cheque.', [
                'noodles' => [2, null], 'tea' => [3, null],
            ]],
            [18, 0, Payment::PAYMENT_METHOD_CASH, 'full', '0.00', '0.00', 'Previous month: customer sale.', [
                'earbuds' => [1, null], 'mouse' => [1, null],
            ]],
            [32, 1, Payment::PAYMENT_METHOD_BANK, 'full', '0.00', '0.00', 'Previous month: bank transfer.', [
                'organiser' => [1, null], 'pen' => [1, null],
            ]],
        ];
    }

    /**
     * The sale carrying this demo tag, or null when it has not been created yet.
     */
    private function existingSale(Business $business, string $key): ?Sale
    {
        return Sale::query()
            ->forBusiness((int) $business->getKey())
            ->where('notes', 'like', '%['.self::SALE_TAG.$key.']%')
            ->first();
    }

    /**
     * Resale price of the latest inventory batch for a product.
     *
     * A `products` row no longer holds a `selling_price`: the price a lot sells
     * at is the price its purchase batch was bought at, recorded on
     * `inventory_batches.selling_price` when the purchase was received. Sales
     * that don't override the unit price lean on this batch price, the same
     * figure the price-options endpoint would confirm server-side.
     */
    private function batchSellingPrice(Product $product): string
    {
        /** @var string|null */
        $price = InventoryBatch::query()
            ->where('product_id', $product->getKey())
            ->whereNotNull('selling_price')
            ->orderByDesc('id')
            ->value('selling_price');

        return $price === null ? '0.00' : (string) $price;
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    /**
     * Part-settle two invoices after the fact.
     *
     * A payment taken later is not the same document as one taken at the
     * counter, and the reports treat the two differently, so the dataset holds
     * both kinds. These go through {@see PaymentService}, which updates the
     * sale's paid/due figures, writes the matching `customer_payment` ledger
     * entry and posts the cash-register movement when the method is cash. No
     * balance is ever edited by hand to make one of these look paid.
     *
     * @param  array<string, Sale>  $sales
     */
    private function extraPayments(Business $business, User $admin, array $sales): void
    {
        $service = app(PaymentService::class);

        // Sale key => [payment method, reference]
        //
        // `sale-11` was left wholly on account, `sale-04` was only half settled
        // at the counter; taking half of what remains from each gives the
        // dataset both a settled-in-part and a still-open invoice without
        // inventing a payment larger than the invoice allows.
        $definitions = [
            'sale-11' => [Payment::PAYMENT_METHOD_BANK, 'DEMO-PAY-11'],
            'sale-04' => [Payment::PAYMENT_METHOD_CASH, 'DEMO-PAY-04'],
        ];

        foreach ($definitions as $saleKey => [$method, $reference]) {
            $sale = $sales[$saleKey] ?? null;

            if ($sale === null || Money::isZero((string) $sale->due_amount)) {
                continue;
            }

            $alreadyRecorded = Payment::query()
                ->forBusiness((int) $business->getKey())
                ->where('reference', $reference)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            // Half the remainder; the service refuses an overpayment anyway.
            $amount = Money::multiply((string) $sale->due_amount, '0.5');

            $service->recordForSale(
                (int) $business->getKey(),
                (int) $admin->getKey(),
                $sale,
                [
                    'amount' => $amount,
                    'payment_method' => $method,
                    'reference' => $reference,
                    'notes' => 'Demo settlement against '.$sale->invoice_number.'.',
                ]
            );
        }

        $this->command?->info('Payments ready.');
    }

    // -----------------------------------------------------------------
    // Sale returns
    // -----------------------------------------------------------------

    /**
     * Two completed returns, chosen so both settlement paths are visible.
     *
     * Both go through {@see SaleReturnService}, which is the only thing that
     * should ever create a return. It restores the stock through the ledger,
     * writes the return amount, derives the COGS reversal from the returned
     * line's stored `unit_cost` (never the product's current cost), and settles
     * the refund against what the sale actually collected:
     *
     *  - `return-01` is against a cash sale, so its refund takes cash out of the
     *    drawer and posts the automatic `refund` register movement;
     *  - `return-02` is against a sale that was never paid, so no method can
     *    refund it and the whole amount is settled on the customer's account
     *    instead — which is what produces a `sale_return` ledger row reducing a
     *    genuinely outstanding receivable.
     *
     * Neither returns more than the original line quantity.
     *
     * @param  array<string, Sale>  $sales
     * @param  array<string, Product>  $products
     */
    private function saleReturns(Business $business, User $admin, array $sales, array $products): void
    {
        $service = app(SaleReturnService::class);

        // key => [sale key, product key, quantity, reason]
        $definitions = [
            'return-01' => ['sale-06', 'backpack', 1, 'Customer changed their mind.'],
            'return-02' => ['sale-11', 'paper', 1, 'Damaged on arrival.'],
        ];

        foreach ($definitions as $key => [$saleKey, $productKey, $quantity, $reason]) {
            $exists = SaleReturn::query()
                ->forBusiness((int) $business->getKey())
                ->where('reason', 'like', '%['.self::SALE_TAG.$key.']%')
                ->exists();

            if ($exists) {
                continue;
            }

            $sale = $sales[$saleKey] ?? null;
            $product = $products[$productKey] ?? null;

            if ($sale === null || $product === null) {
                continue;
            }

            // The return service works in terms of `sale_item_id`, so the line is
            // resolved from this sale's own items by product rather than by an id
            // baked into the fixture — that id is only known once the sale
            // exists.
            $saleItem = $sale->saleItems()
                ->where('product_id', $product->getKey())
                ->orderBy('id')
                ->first();

            if ($saleItem === null || (float) $saleItem->quantity < $quantity) {
                $this->command?->warn("Could not resolve a returnable line for {$key}; skipped.");

                continue;
            }

            $service->create((int) $business->getKey(), (int) $admin->getKey(), [
                'sale_id' => (int) $sale->getKey(),
                'return_date' => Carbon::now()->subDays(2)->toDateString(),
                'reason' => '['.self::SALE_TAG.$key.'] '.$reason,
                'items' => [
                    ['sale_item_id' => (int) $saleItem->getKey(), 'quantity' => $quantity],
                ],
            ]);
        }

        $this->command?->info('Sale returns ready.');
    }

    // -----------------------------------------------------------------
    // Expenses
    // -----------------------------------------------------------------

    /**
     * Eight expenses across the four categories, in cash, card and bank.
     *
     * Written through {@see ExpenseService}, so a cash expense posts its own
     * automatic register movement and the expense report reads real rows. The
     * `DEMO-EXP-###` reference is both a realistic reference number and the
     * rerun key.
     *
     * @param  array<string, ExpenseCategory>  $categories
     */
    private function expenses(Business $business, User $admin, array $categories): void
    {
        $service = app(ExpenseService::class);

        // [category, amount, days ago, method, description]
        $definitions = [
            ['rent', '25000.00', 5, Expense::PAYMENT_METHOD_BANK, 'Shop rent for this month'],
            ['utilities', '4100.00', 12, Expense::PAYMENT_METHOD_BANK, 'Electricity bill'],
            ['office', '1650.00', 8, Expense::PAYMENT_METHOD_CASH, 'Stationery order'],
            ['office', '2200.00', 2, Expense::PAYMENT_METHOD_CASH, 'Printer toner and paper'],
            ['transport', '1200.00', 1, Expense::PAYMENT_METHOD_CASH, 'Delivery van fuel'],
            ['transport', '950.00', 0, Expense::PAYMENT_METHOD_CASH, 'Staff travel allowance'],
            ['utilities', '3800.00', 3, Expense::PAYMENT_METHOD_CARD, 'Water and gas bill'],
            ['office', '750.00', 0, Expense::PAYMENT_METHOD_CASH, 'Cleaning supplies'],
        ];

        foreach ($definitions as $index => [$categoryKey, $amount, $daysAgo, $method, $description]) {
            $reference = 'DEMO-EXP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            $exists = Expense::query()
                ->forBusiness((int) $business->getKey())
                ->where('reference', $reference)
                ->exists();

            if ($exists) {
                continue;
            }

            $service->create((int) $business->getKey(), (int) $admin->getKey(), [
                'expense_category_id' => (int) $categories[$categoryKey]->getKey(),
                'amount' => $amount,
                'expense_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                'payment_method' => $method,
                'reference' => $reference,
                'description' => $description,
            ]);
        }

        $this->command?->info('Expenses ready.');
    }
}
