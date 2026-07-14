<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\Interface;

interface CurrentUser
{
    public function blockedProfileEdit(): bool;

    public function isLogged(): bool;

    public function isBanned(): bool;

    public function isAdmin(bool $checkAdminDataAllRights = false): bool;

    public function checkAllRights(string $right_id): bool;

    public function authLogout(?string $byeMessage = null): void;

    public function auth(): void;

    public function authSetUserData(array $userData): void;

    public function getId(): int|string|null;

    public function setId(int|string|null $id): static;

    public function id(): int|string|null;

    public function getSid(): ?int;

    public function setSid(?int $sid): static;

    public function sid(): ?int;

    public function getAllRights(): array;

    public function setAllRights(string|array|null $allRights): static;

    public function getBazeCount(): int;

    public function setBazeCount(int $bazeCount): static;

    public function getBlockSaveReferer(): bool;

    public function setBlockSaveReferer(bool $blockSaveReferer): static;

    public function getBlockAutoRedirect(): bool;

    public function setBlockAutoRedirect(bool $blockAutoRedirect): static;

    public function getAdminData(): ?array;

    public function setAdminData(array $adminData): static;

    public function isAuthenticatedViaBearer(): bool;

    public function setAuthenticatedViaBearer(bool $authenticatedViaBearer): static;
}
