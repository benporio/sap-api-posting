<?php

enum EntityType: int {
    case GOODS_RECEIPT_PO = 20;
    case AR_INVOICE = 13;
    case GOODS_RETURN = 21;
    case AR_DOWN_PAYMENT = 203;
    case AR_CREDIT_MEMO = 14;
    case AP_CREDIT_MEMO = 19;
    case INCOMING_PAYMENT = 24;
    case OUTGOING_PAYMENT = 46;

    public function name(): string {
        return match($this) 
        {
            EntityType::GOODS_RECEIPT_PO => 'PurchaseDeliveryNotes',   
            EntityType::AR_INVOICE => 'Invoices',   
            EntityType::GOODS_RETURN => 'PurchaseReturns',   
            EntityType::AR_DOWN_PAYMENT => 'DownPayments',   
            EntityType::AR_CREDIT_MEMO => 'CreditNotes',   
            EntityType::AP_CREDIT_MEMO => 'PurchaseCreditNotes',   
            EntityType::INCOMING_PAYMENT => 'IncomingPayments',   
            EntityType::OUTGOING_PAYMENT => 'VendorPayments',   
        };
    }

    public function invoiceType(): string {
        return match($this) 
        {
            EntityType::GOODS_RECEIPT_PO => 'it_PurchaseDeliveryNote',   
            EntityType::AR_INVOICE => 'it_Invoice',   
            EntityType::GOODS_RETURN => 'it_PurchaseReturn',   
            EntityType::AR_DOWN_PAYMENT => 'it_DownPayment',   
            EntityType::AR_CREDIT_MEMO => 'it_CredItnote',   
            EntityType::AP_CREDIT_MEMO => 'it_PurchaseCreditNote',   
            EntityType::INCOMING_PAYMENT => 'it_Receipt',   
            EntityType::OUTGOING_PAYMENT => 'it_PaymentAdvice',   
        };
    }
}

class DocumentInfo {
    public function __construct(
        public EntityType $type,
        public object $rawRequestSapObj,
        public object $documentObj,
        public int $docEntry
    ) { }
}

class ApiPostingService {
    private array $addedDocumentInfos = []; 

    public function __construct(
        private string $server,
        private string $apiSessionId
    ) { }

    public function callDocumentApi(string $entityPath, object $documentObj = null): ?object {
        $headers[] = 'Cookie: B1SESSION='.$this->apiSessionId.';';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_URL, 'https://'.$this->server.":50000/b1s/v1/$entityPath");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        curl_setopt($curl, CURLOPT_POST, true);
        if (!is_null($documentObj)) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($documentObj));
        $response = curl_exec($curl);
        curl_close($curl);
        $resObj = json_decode($response);
        return $resObj;
    }

    public function createSAPDocument(array $args, int $objectType, Closure $processCreatedDocObj = null): object {
        try {
            $entity = EntityType::from($objectType);
            $entityName = $entity->name();
            [ 'server' => $server, ] = $args;
            $SAPObj = $this->reconstructRequestSapObj($args);
            if (isset($SAPObj)) {
                $documentObj = $this->parseDocObjFromRequestObj($entity, $SAPObj, $args);
                if (isset($processCreatedDocObj)) $processCreatedDocObj($SAPObj, $documentObj);
                $resObj = $this->callDocumentApi($entityName, (object)$documentObj);
                if (!is_null($resObj) && !isset($resObj->error) && !isset($resObj->code)) {
                    $this->addedDocumentInfos[] = new DocumentInfo($entity, $SAPObj, (object)$documentObj, $resObj->DocEntry);
                }
                return $resObj;
            }
        } catch (\Throwable $th) {
            return (object)[
                'error' => [
                    'code' => 'unknown',
                    'message' => [
                        'raw' => strval($th),
                        'value' => $th->getMessage()
                    ],
                ]
            ];
        }
    }

    public function cancelAddedDocuments() {
        for ($i = count($this->addedDocumentInfos) - 1; $i > 0; $i--) { 
            $addedDocumentInfo = $this->addedDocumentInfos[$i];
            $this->cancelAddedDocument($addedDocumentInfo->type, $addedDocumentInfo->docEntry, $addedDocumentInfo);
        }
    }

    public function cancelAddedDocument(int|EntityType  $objectType, int $docEntry, DocumentInfo $docInfo = null) {
        $entity = is_int($objectType) ? EntityType::from($objectType) : $objectType;
        switch ($entity) {
            case EntityType::AR_DOWN_PAYMENT:
                unset($docInfo->documentObj->JournalMemo);
                foreach ($docInfo->documentObj->DocumentLines as $i => &$line) {
                    $line['BaseEntry'] = $docEntry;
                    $line['BaseLine'] = $i;
                    $line['BaseType'] = $entity->value;
                }
                $this->callDocumentApi(EntityType::AR_CREDIT_MEMO->name(), $docInfo->documentObj);
            default:
                $this->callDocumentApi($entity->name()."($docEntry)/Cancel");
                break;
        }
    }

    public function postProcessApiPosting(object $resObj, object $SAPObj, bool $enableExternalLogging = true): object {
        $data = null;
        if (!isset($resObj->error) && !isset($resObj->code)) {
            $data = $this->processSuccessLog($resObj, $SAPObj, $enableExternalLogging);
        } else {
            $data = $this->processFailedLog($resObj);
        }
        return $data;
    }

    private function processFailedLog(object $resObj): object {
        $data = (object)[
            "valid" => false, 
            "msg" => isset($resObj->error) ? $resObj->error->message->value : $resObj->message->value,
            "raw" => $resObj
        ];
        return $data;
    }

    private function processSuccessLog(object $resObj, object $SAPObj, bool $enableExternalLogging = true): object {
        $docEntry = $resObj->DocEntry;
        $data = (object)[
            "valid"=>true, 
            "msg"=>"Operation completed successfully - " .$docEntry,
            "docref"=>$docEntry,
            "docentry"=>$docEntry
        ];
        if (!$enableExternalLogging) return $data;
        $payload = json_encode(array(
            "logMessage"=> "PROGRESS ~ $SAPObj->recentUploadedFile ~ POSTED ~ $SAPObj->tabName ($SAPObj->count) ~ DocEntry:$docEntry, RefNo:$SAPObj->refNo, ".count($SAPObj->detailLines)." line(s)",
            "serial" => $SAPObj->serial
        ));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,'http://'.$SAPObj->serverIP.':3003/log');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $APIOutput = json_decode(curl_exec($ch));
        curl_close ($ch);
        return $data;
    }

    private function reconstructRequestSapObj(mixed $args): object {
        [
            'SAPObj' => $SAPObj,
            'explicitFields' => $explicitFields,
        ] = $args;
        if (isset($SAPObj)) {
            foreach ($explicitFields as $key => $value) {
                $SAPObj->{$key} = $value;
            }
        }
        return $SAPObj;
    }

    private function parseDocObjFromRequestObj(EntityType $type, object $SAPObj, array $args): array {
        $lineExplicitFields = $SAPObj->lineExplicitFields;
        $headerExplicitFields = $SAPObj->headerExplicitFields;
        $documentObj = [
            'CardCode' => $SAPObj->cardCode,
            'TaxDate' => $SAPObj->taxDate,
            'DocDate' => $SAPObj->taxDate,
        ];
        if (in_array($type, [EntityType::INCOMING_PAYMENT, EntityType::OUTGOING_PAYMENT])) {
            $documentObj['BPLID'] = $SAPObj->branchId;
            if ($type === EntityType::INCOMING_PAYMENT) {
                if (isset($args['actualDeposit']) && (bool)$args['actualDeposit']) {
                    $documentObj['CashSum'] = $args['actualDeposit'];
                } else {
                    $documentObj['CashSum'] = 0;
                }
                $documentObj['CashAccount'] = $SAPObj->cashAccount;
            } else {
                if (isset($args['actualDeposit']) && (bool)$args['actualDeposit']) {
                    $documentObj['TransferSum'] = $args['actualDeposit'];
                } else{
                    $documentObj['TransferSum']  = 0;
                }
                $documentObj['TransferAccount'] = $SAPObj->cashAccount;
            }
            $paymentInvoices = [];
            foreach ($args['paymentInvoices'] as $invoice) {
                if ($invoice['sumApplied'] == 0) continue;
                $paymentInvoice = [];
                $paymentInvoice['DocEntry'] = $invoice['docEntry'];
                $paymentInvoice['InvoiceType'] = EntityType::from(intval($invoice['objectType']))->invoiceType();
                $paymentInvoice['SumApplied'] = $invoice['sumApplied'];
                $paymentInvoices[] = $paymentInvoice;
            }
            $documentObj['PaymentInvoices'] = $paymentInvoices;
            $documentObj['DocType'] = 'rCustomer';
        } else {
            
            $documentObj['DocType'] = $SAPObj->serviceType == 'I' ? 'dDocument_Items' : 'dDocument_Service';
            $documentObj['SalesPersonCode'] = $SAPObj->txtSalesEmpCode;
            $documentObj['DocumentsOwner'] = $SAPObj->txtOwnerCode;
            $documentObj['JournalMemo'] = $SAPObj->txtJournalMemo;
            $documentObj['DocumentLines'] = array_map(function($line) use($lineExplicitFields, $SAPObj, $args) {
                $lineObj = [
                    'Quantity' => $line->quantity,
                ];
                if ($SAPObj->serviceType == 'I') {
                    $lineObj['ItemCode'] = $line->itemCode;
                } else {
                    $lineObj['AccountCode'] = $line->itemCode;
                }
                if (isset($args['isLineCostPriceAfVat']) && $args['isLineCostPriceAfVat']) {
                    if (isset($SAPObj->isNegativeAmount)) {
                        if ($SAPObj->isNegativeAmount) {
                            $lineObj['PriceAfterVAT'] = $line->cost;
                        } else {
                            $lineObj['PriceAfterVAT'] = abs($line->cost);
                        }
                    } else {
                        $lineObj['PriceAfterVAT'] = $line->cost;
                    }
                } else {
                    $lineObj['Price'] = $line->cost;
                }
                if (isset($line->description) && $line->description != '') {
                    $lineObj['ItemDescription'] = $line->description;
                }
                if (isset($line->text) && $line->text != '') {
                    $lineObj['ItemDetails'] = $line->text;
                }
                if (isset($args['lineProcessor']) && isset($args['lineProcessor']['customBaseLineSettings'])) {
                    $args['lineProcessor']['customBaseLineSettings']($SAPObj, $args, $lineObj, $line);
                } else {
                    if (isset($line->baseEntry)) {
                        $lineObj['BaseEntry'] = $line->baseEntry;
                        $lineObj['BaseLine'] = $line->baseLine;
                        $lineObj['BaseType'] = $line->baseType;
                    }
                }
                if (isset($line->taxCode) && $line->taxCode != '') {
                    $lineObj['VatGroup'] = $line->taxCode;
                }
                if (isset($lineExplicitFields) && (bool)$lineExplicitFields) {
                    foreach ($lineExplicitFields as $key => $value) {
                        if (is_null($value)) continue;
                        if (str_contains($value, 'DEFAULT')) {
                            $lineObj[$key] = $line->{trim(explode('-', $value)[1])};
                        } else {
                            $lineObj[$key] = $value;
                        }
                    }
                }
                if (isset($line->uoMEntryToUse) && $line->uoMEntryToUse != '') $lineObj['UoMEntry'] = $line->uoMEntryToUse;
                if (isset($line->udf)) {
                    foreach ($line->udf as $UDF) {
                        $field = $UDF->columnName;
                        $val = $UDF->value;
                        if ($val != '' && !is_null($val) && $val != 'undefined' && !empty($val)) {
                            $lineObj[$field] = $val;
                        }
                    }
                }
                return $lineObj;
            }, $SAPObj->detailLines);
            $documentObj['BPL_IDAssignedToInvoice'] = $SAPObj->branchId;
            if (isset($SAPObj->dueDate) && $SAPObj->dueDate != '') {
                $documentObj['DocDueDate'] = $SAPObj->dueDate;
            } else if (isset($SAPObj->docDueDate) && $SAPObj->docDueDate != '') {
                $documentObj['DocDueDate'] = $SAPObj->docDueDate;
            } else {
                $documentObj['DocDueDate'] = $SAPObj->taxDate;
            }
            if (isset($SAPObj->refNo) && $SAPObj->refNo != '') {
                $documentObj['NumAtCard'] = $SAPObj->refNo;
            }
        }
        if (isset($headerExplicitFields) && (bool)$headerExplicitFields) {
            foreach ($headerExplicitFields as $key => $value) {
                if (is_null($value)) continue;
                $documentObj[$key] = $value;
            }
        }
        return $documentObj;
    }
}