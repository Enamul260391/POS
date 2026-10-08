<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use ReflectionClass;
use Tests\TestCase;

class ModelStructureTest extends TestCase
{
    private const MODELS = [
        Business::class, BusinessSetting::class, CashRegister::class,
        CashRegisterTransaction::class, Category::class, Customer::class,
        Expense::class, ExpenseCategory::class, Payment::class,
        Permission::class, Product::class, Purchase::class,
        PurchaseItem::class, Role::class, Sale::class,
        SaleItem::class, SaleReturn::class, SaleReturnItem::class,
        StockMovement::class, Supplier::class, User::class,
    ];

    private const SOFT_DELETE_MODELS = [
        Business::class, Category::class, Product::class,
        Customer::class, Supplier::class, ExpenseCategory::class,
    ];

    private const NON_SOFT_DELETE_MODELS = [
        BusinessSetting::class, User::class, Role::class,
        Permission::class, StockMovement::class, Purchase::class,
        PurchaseItem::class, Sale::class, SaleItem::class,
        Payment::class, SaleReturn::class, SaleReturnItem::class,
        Expense::class, CashRegister::class, CashRegisterTransaction::class,
    ];

    public function test_all_required_models_exist(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertTrue(class_exists($model), "Model {$model} should exist");
            $this->assertTrue((new ReflectionClass($model))->isSubclassOf(Model::class));
        }
    }

    public function test_soft_deletes_configured_correctly(): void
    {
        foreach (self::SOFT_DELETE_MODELS as $model) {
            $this->assertContains(SoftDeletes::class, $this->traits(new ReflectionClass($model)));
        }
        foreach (self::NON_SOFT_DELETE_MODELS as $model) {
            $this->assertNotContains(SoftDeletes::class, $this->traits(new ReflectionClass($model)));
        }
    }

    public function test_user_uses_sanctum_has_api_tokens(): void
    {
        $this->assertContains(HasApiTokens::class, $this->traits(new ReflectionClass(User::class)));
    }

    public function test_user_hidden_fields_are_secure(): void
    {
        $user = new User;
        $this->assertContains('password', $user->getHidden());
        $this->assertContains('remember_token', $user->getHidden());
    }

    public function test_no_model_uses_empty_guarded(): void
    {
        foreach (self::MODELS as $model) {
            if ($model === User::class) {
                continue;
            }
            $this->assertNotSame([], (new $model)->getGuarded());
        }
    }

    public function test_user_fillable_has_expected_fields(): void
    {
        $fillable = (new User)->getFillable();
        $this->assertContains('business_id', $fillable);
        // Platform identity marker (nullable; NULL = not a platform identity).
        $this->assertContains('platform_role', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('email', $fillable);
        $this->assertContains('phone', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertNotContains('remember_token', $fillable);
    }

    public function test_user_relationships(): void
    {
        $r = new ReflectionClass(User::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsToMany::class, $r->getMethod('roles')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('stockMovements')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('purchases')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('sales')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('payments')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('expenses')->getReturnType()->getName());
    }

    public function test_business_and_setting_relationships(): void
    {
        $this->assertEquals(HasMany::class, (new ReflectionClass(Business::class))->getMethod('settings')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, (new ReflectionClass(Business::class))->getMethod('users')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, (new ReflectionClass(BusinessSetting::class))->getMethod('business')->getReturnType()->getName());
    }

    public function test_category_and_product_relationships(): void
    {
        $cat = new ReflectionClass(Category::class);
        $this->assertEquals(BelongsTo::class, $cat->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $cat->getMethod('products')->getReturnType()->getName());
        $prod = new ReflectionClass(Product::class);
        $this->assertEquals(BelongsTo::class, $prod->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $prod->getMethod('category')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $prod->getMethod('saleItems')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $prod->getMethod('purchaseItems')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $prod->getMethod('stockMovements')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $prod->getMethod('saleReturnItems')->getReturnType()->getName());
    }

    public function test_role_permission_relationships(): void
    {
        $role = new ReflectionClass(Role::class);
        $this->assertEquals(BelongsToMany::class, $role->getMethod('users')->getReturnType()->getName());
        $this->assertEquals(BelongsToMany::class, $role->getMethod('permissions')->getReturnType()->getName());
        $perm = new ReflectionClass(Permission::class);
        $this->assertEquals(BelongsToMany::class, $perm->getMethod('roles')->getReturnType()->getName());
    }

    public function test_stock_movement_relationships(): void
    {
        $r = new ReflectionClass(StockMovement::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('product')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('creator')->getReturnType()->getName());
        $this->assertEquals(MorphTo::class, $r->getMethod('reference')->getReturnType()->getName());
    }

    public function test_supplier_relationships(): void
    {
        $r = new ReflectionClass(Supplier::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('purchases')->getReturnType()->getName());
    }

    public function test_purchase_relationships(): void
    {
        $r = new ReflectionClass(Purchase::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('supplier')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('creator')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('purchaseItems')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('payments')->getReturnType()->getName());
    }

    public function test_purchase_item_relationships(): void
    {
        $r = new ReflectionClass(PurchaseItem::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('purchase')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('product')->getReturnType()->getName());
    }

    public function test_customer_relationships(): void
    {
        $r = new ReflectionClass(Customer::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('sales')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('saleReturns')->getReturnType()->getName());
    }

    public function test_sale_relationships(): void
    {
        $r = new ReflectionClass(Sale::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('customer')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('cashier')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('saleItems')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('payments')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('saleReturns')->getReturnType()->getName());
    }

    public function test_sale_item_relationships(): void
    {
        $r = new ReflectionClass(SaleItem::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('sale')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('product')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('saleReturnItems')->getReturnType()->getName());
    }

    public function test_payment_relationships(): void
    {
        $r = new ReflectionClass(Payment::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('sale')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('purchase')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('receiver')->getReturnType()->getName());
    }

    public function test_sale_return_relationships(): void
    {
        $r = new ReflectionClass(SaleReturn::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('sale')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('customer')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('creator')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $r->getMethod('saleReturnItems')->getReturnType()->getName());
    }

    public function test_sale_return_item_relationships(): void
    {
        $r = new ReflectionClass(SaleReturnItem::class);
        $this->assertEquals(BelongsTo::class, $r->getMethod('saleReturn')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('saleItem')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $r->getMethod('product')->getReturnType()->getName());
    }

    public function test_expense_and_category_relationships(): void
    {
        $ec = new ReflectionClass(ExpenseCategory::class);
        $this->assertEquals(BelongsTo::class, $ec->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $ec->getMethod('expenses')->getReturnType()->getName());
        $e = new ReflectionClass(Expense::class);
        $this->assertEquals(BelongsTo::class, $e->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $e->getMethod('expenseCategory')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $e->getMethod('creator')->getReturnType()->getName());
    }

    public function test_cash_register_and_transaction_relationships(): void
    {
        $cr = new ReflectionClass(CashRegister::class);
        $this->assertEquals(BelongsTo::class, $cr->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $cr->getMethod('openedBy')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $cr->getMethod('closedBy')->getReturnType()->getName());
        $this->assertEquals(HasMany::class, $cr->getMethod('transactions')->getReturnType()->getName());
        $crt = new ReflectionClass(CashRegisterTransaction::class);
        $this->assertEquals(BelongsTo::class, $crt->getMethod('business')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $crt->getMethod('cashRegister')->getReturnType()->getName());
        $this->assertEquals(BelongsTo::class, $crt->getMethod('creator')->getReturnType()->getName());
        $this->assertEquals(MorphTo::class, $crt->getMethod('reference')->getReturnType()->getName());
    }

    private function traits(ReflectionClass $r): array
    {
        $traits = $r->getTraitNames();
        if ($r->getParentClass()) {
            $traits = array_merge($traits, $this->traits($r->getParentClass()));
        }

        return array_unique($traits);
    }
}
