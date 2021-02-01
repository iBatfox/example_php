<?
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
/**
 * @global CMain $APPLICATION
 * @var CBitrixComponent $this
 */

use Sa\Sotbit\B2BShop\User;
use Sa\Sotbit\B2BShop\Controller;

use \Bitrix\Sale\Order as BXOrder;
use \Bitrix\Sale\Basket as BXBasket;
use \Bitrix\Main\Context as BXContext;
use Bitrix\Main\Application as BXApplication;



global $USER, $APPLICATION;
if(!$USER->IsAuthorized()) {
    return;
}
$userId = $USER->GetId();
$siteId = BXContext::getCurrent()->getSite();

$b2bUser = new User();
$catalogId = $b2bUser->getCatalog();
$contractId = $b2bUser->getContract();
$currency = $b2bUser->getOption("currency");

$app = Controller::get();
$app->loadBXClasses();

$buyerType = $app->getId("buyer_type", $app->getOption("default_buyer_type"));

// initial data array
$data = [
    'items' => [],
    'total_volume' => 0,
    'total_weight' => 0,
    'total_pallets' => 0,
    'action_path' => $APPLICATION->GetCurDir()
];

$request = BXApplication::getInstance()->getContext()->getRequest();
$sumbitted = strlen($request->getPost("route_list_submit"));
if($sumbitted) {
    $orderedItems = $request->getPost("ordered");
    $totalOrdered = $request->getPost("total_ordered");

    $restItems = [];
    foreach($orderedItems as $itemId => $itemQuantity) {
        $restQuantity = $totalOrdered[$itemId] - $itemQuantity;
        if($restQuantity > 0) {
            $restItems[$itemId] = $restQuantity;
        }
        if($itemQuantity <= 0) {
            unset($orderedItems[$itemId]);
        }
    }

    if(is_array($orderedItems) && count($orderedItems)) {
        $createRestOrder = is_array($restItems) && count($restItems);

        // creation
        $orderMain = BXOrder::create($siteId, $userId);
        if($createRestOrder) {
            $orderRest = BXOrder::create($siteId, $userId);
        }

        // status
        $orderMain->setField('STATUS_ID', $app->getId("order_status", "route_sheet"));
        if($createRestOrder) {
            $orderRest->setField('STATUS_ID', $app->getId("order_status", "route_sheet_rest"));
        }
        
        // person type
        $personTypeId = $app->getId(
            'buyer_type',
            $app->getOption('default_buyer_type')
        );
        $orderMain->setPersonTypeId($personTypeId);
        if($createRestOrder) {
            $orderRest->setPersonTypeId($personTypeId);
        }

        // general currency
        $orderMain->setField('CURRENCY', $currency);
        if($createRestOrder) {
            $orderRest->setField('CURRENCY', $currency);
        }

        // old orders
        $oldOrders = $request->getPost("orders");

        // properties
        $propertyCollection = $orderMain->getPropertyCollection();
        foreach ($propertyCollection as $propertyItem) {

            if($propertyItem->getField("CODE") == "ROUTE_LIST_ORDERS") {
                $propertyItem->setField("VALUE", implode(",", array_unique($oldOrders)));
            }

        }

        // basket
        $orderMainBasket = BXBasket::create($siteId);
        foreach($orderedItems as $productId => $quantity) {
			$item = $orderMainBasket->createItem('catalog', $productId);
			$item->setFields(array(
				'QUANTITY' => $quantity,
				'CURRENCY' => $currency,
				'LID' => $siteId,
				'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
			));
		}
		$orderMainBasket->save();
        $orderMain->setBasket($orderMainBasket);
        if($createRestOrder) {
            $orderRestBasket = BXBasket::create($siteId);
            foreach($restItems as $productId => $quantity) {
                $item = $orderRestBasket->createItem('catalog', $productId);
                $item->setFields(array(
                    'QUANTITY' => $quantity,
                    'CURRENCY' => $currency,
                    'LID' => $siteId,
                    'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
                ));
            }
            $orderRestBasket->save();
            $orderRest->setBasket($orderRestBasket);
        }

        // saving
        $orderMain->doFinalAction(true);
        $resultMain = $orderMain->save();
        if($createRestOrder) {
            $orderRest->doFinalAction(true);
            $resultRest = $orderRest->save();
        }

        // orders id
        $data["orderId"] = intval($orderMain->getId());
        if($createRestOrder) {
            $data["orderRestId"] = intval($orderRest->getId());
        }

        // cancel old orders
        if($data["orderId"] && is_array($oldOrders) && count($oldOrders)) {
            foreach($oldOrders as $oldOrderId) {
                $oldOrder = BXOrder::load($oldOrderId);
                $oldOrder->setField("CANCELED", "Y");
                $oldOrder->setField("STATUS_ID", $app->getId("order_status", "canceled"));
                $oldOrder->save();
            }
        }
        /*echo '<pre>';
        var_dump($resultMain, $resultRest);
        echo '</pre>';*/
    }
}

// contract property id
$it = \Bitrix\Sale\Internals\OrderPropsTable::getList(array(
    'select' => ["ID"],
    'filter' => [
        "CODE" => "CONTRACT",
        "PERSON_TYPE_ID" => $buyerType
    ],
));
if($row = $it->Fetch()) {
    $contractPropertyId = $row["ID"];
} else {
    die('Error. Code: 1');
}

// orders query
$it = BXOrder::getList([
    'filter' => [
        'USER_ID' => $userId,
        'PROPS.ORDER_PROPS_ID' => $contractPropertyId,
        'PROPS.VALUE' => $contractId,
        'STATUS_ID' => [
            $app->getId("order_status", "work_sheet"),
            $app->getId("order_status", "route_sheet_rest")
        ]
    ],
    'select' => ['ID', 'STATUS_ID'],
    'runtime' => [
        new \Bitrix\Main\Entity\ReferenceField(
            'PROPS',
            '\Bitrix\Sale\Internals\OrderPropsValueTable',
            ['=this.ID' => 'ref.ORDER_ID']
        ),                 
    ]
]);
while($row = $it->Fetch()) {
    $order = BXOrder::load($row["ID"]);

    $items = $order->getBasket()->getOrderableItems();

    foreach($items as $item) {

        if(!isset($data['items'][$item->getProductId()])) {
            $data['items'][$item->getProductId()] = [
                'name' => $item->getField('NAME'),
                'price' => $item->getPrice(),
                'currency' => $item->getCurrency(),
                'weight' => round($item->getWeight()/1000, 3),
                'total_quantity' => $item->getQuantity(),
                'orders' => [$row["ID"]]
            ];
        } else {
            $data['items'][$item->getProductId()]['total_quantity'] += $item->getQuantity();
            if(!in_array(
                $row["ID"],
                $data['items'][$item->getProductId()]['orders']
            )) {
                $data['items'][$item->getProductId()]['orders'][] = $row["ID"];
            }
        }
    }
}

$itemsIds = array_keys($data['items']);
if(is_array($itemsIds) && count($itemsIds) && intval($itemsIds[0])) {

    // additional info from catalog
    $select = ["ID", "IBLOCK_ID", "DETAIL_PAGE_URL", "CATALOG_GROUP_1", "PROPERTY_ARTIKUL_EN", "PROPERTY_CML2_ARTICLE", "PROPERTY_ON_PALLET"];
    $filter = ["IBLOCK_ID" => $catalogId, "ID" => $itemsIds];
    $it = CIblockElement::GetList([], $filter, false, false, $select);
    while($row = $it->GetNext()) {

        $itemData = &$data['items'][$row["ID"]];

        // volume calculations
        $volume = $row["CATALOG_LENGTH"]*$row["CATALOG_WIDTH"]*$row["CATALOG_HEIGHT"];
        $itemData["volume"] = $volume;
        $itemData["total_volume"] = $itemData["volume"] * $itemData["total_quantity"];
        
        // available and result quantity
        $itemData["available_quantity"] = $row["CATALOG_QUANTITY"] > 0
            ? $row["CATALOG_QUANTITY"]
            : 0;
        $itemData["result_quantity"] = min($itemData["total_quantity"], $itemData["available_quantity"]);

        // calculate weight
        $itemData['total_weight'] = $itemData["weight"] > 0
            ? $itemData["weight"] * $itemData["result_quantity"]
            : '-';

        // artnumber
        $itemData["artnumber"] = $row["PROPERTY_ARTIKUL_EN_VALUE"] ?: $row["PROPERTY_CML2_ARTICLE_VALUE"];

        // link
        $itemData["link"] = $row["DETAIL_PAGE_URL"];

        // pallets
        $itemData["on_pallet"] = $row["PROPERTY_ON_PALLET_VALUE"];
        $itemData["pallets"] = $itemData["result_quantity"] > 0 
            ? $row["PROPERTY_ON_PALLET_VALUE"]
                ? round($itemData["result_quantity"]/$row["PROPERTY_ON_PALLET_VALUE"], 2)
                : '-'
            : 0;
        /*$itemData["measure_ratio"] = CCatalogMeasureRatio::GetList(
            [],
            ["PRODUCT_ID" => $row["ID"]]
        )->Fetch()["RATIO"];*/

        // total calculations
        if($data["total_volume"] !== '-') {
            $data["total_volume"] = $itemData["total_volume"] > 0
                ? $data["total_volume"]+$itemData["total_volume"]
                : '-';
        }
        if($data["total_weight"] !== '-') {
            $data["total_weight"] = $itemData["total_weight"] !== '-'
                ? $data["total_weight"]+$itemData["total_weight"]
                : '-';
        }
        if($data["total_pallets"] !== '-') {
            $data["total_pallets"] = $itemData["pallets"] !== '-'
                ? $data["total_pallets"]+$itemData["pallets"]
                : '-';
        }

    }
}

/*
echo '<pre>';
var_dump($data);
echo '</pre>';
*/

$arResult = $data;

$this->IncludeComponentTemplate();


