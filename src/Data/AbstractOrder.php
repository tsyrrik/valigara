<?php

namespace App\Data;

abstract class AbstractOrder
{

    private int $id;
    public ?array $data;

    abstract protected function loadOrderData(int $id): array;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    final public function getOrderId(): int
    {
        return $this->id;
    }

    final public function load(): void
    {
        $this->data = $this->loadOrderData($this->getOrderId());
    }

}
