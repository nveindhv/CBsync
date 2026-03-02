<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\KmsClient;

class KmsController extends Controller
{
    public function __construct(protected KmsClient $kms) {}

    // PRODUCTS
    public function getProducts(Request $request)
    {
        return $this->kms->getProducts($request->all());
    }

    public function upsertProducts(Request $request)
    {
        return $this->kms->createUpdateProducts($request->input('products', []));
    }

    // ORDERS
    public function getOrders(Request $request)
    {
        return $this->kms->getFinances($request->all());
    }

    public function createOrder(Request $request)
    {
        return $this->kms->createOrder($request->all());
    }

    public function updateFinance(Request $request)
    {
        return $this->kms->updateFinance($request->all());
    }

    // CUSTOMERS
    public function upsertBusiness(Request $request)
    {
        return $this->kms->createUpdateBusiness($request->all());
    }

    public function updatePriceAgreements(Request $request)
    {
        return $this->kms->updatePriceAgreements($request->all());
    }
}
