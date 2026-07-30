<?php
declare(strict_types=1);
namespace CF7AIV\Admin;

interface CalibrationRunStoreInterface {
	/** @param array<string,mixed> $criteria */
	public function create(string $runId,string $sourceKey,int $administratorId,array $criteria):bool;
	public function activate(string $runId,int $scheduled):bool;
	public function cancel(string $runId):bool;
	public function state(string $runId):?string;
	/** Runs a short local mutation while holding the authoritative active-run row lock. */
	public function whileActive(string $runId,callable $operation):bool;
}
