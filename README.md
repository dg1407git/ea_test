Тестовая задача в виде модуля.
Для запуска:
php 8.3
Устанавливаем б24 через установочный скрипт https://www.1c-bitrix.ru/download/cms.php#tab-subsection-2
Добавляем файлы из репозитория
В корень модуля test добавляем файл .env с содержимым.
COURIER_MOCK=http://b24.loc/courierQuoteMock.php
b24.loc меняем на свой домен
Накатываем бекап.
После всех проделанных манипуляци рекомендованно настроить Главный модуль и указать домен.
Для запуска тестов:
composer update
vendor\bin\phpunit tests/unit/modules/test/AppTest.php



