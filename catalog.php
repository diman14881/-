<?php
/*
 * ============================================================
 *  ФАЙЛ: catalog.php — ГЛАВНЫЙ ФАЙЛ САЙТА
 * ============================================================
 *  ЗА ЧТО ОТВЕЧАЕТ (по порядку в файле):
 *   1. Настройки (пути к файлам, Telegram)
 *   2. Функции-помощники (защита текста, сжатие фото, чтение/запись товаров)
 *   3. Вход в админку по паролю, смена пароля, выход
 *   4. Добавление / редактирование / удаление товара (+ АРТИКУЛ)
 *   5. Витрина: поиск, категории, карточки товаров
 *   6. Всплывающее окно товара, чат поддержки
 *  НОВОЕ: у каждого товара есть АРТИКУЛ — короткий номер (101, 318…).
 *  Артикул виден ТОЛЬКО админу: в форме товара, на карточке витрины
 *  и в окне товара. Покупатели его не видят.
 *  ПРАВИЛО: сначала сохраняй копию файла, потом меняй!
 * ============================================================
 */

// Запускаем «сессию» — она хранит в браузере: залогинен ли админ и что лежит в корзине
session_start();

// --- НАСТРОЙКИ: где лежат файлы сайта ---
$database_file = __DIR__ . '/database.txt';   // тут хранятся все товары (это и есть «база данных»)
$password_file = __DIR__ . '/pass.txt';       // тут хранится пароль админа
$upload_dir = __DIR__ . '/uploads/';          // тут хранятся загруженные фотографии

/* Впишите Telegram-имя без @, чтобы показывать кнопку заказа. */
$telegram_username = '';

/*
 * ФУНКЦИЯ e() — «щит» для любого текста.
 * Превращает опасные символы (<, >, ", ') в безопасные,
 * чтобы злоумышленник не смог вписать в название товара вредоносный код.
 * ПРАВИЛО: ЛЮБОЙ текст перед показом на странице прогоняй через e(...)
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ФУНКЦИЯ lowerText() — переводит текст в нижний регистр. Нужна для поиска (чтобы «ТЕЛЕФОН» и «телефон» находились одинаково)
function lowerText(string $text): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($text, 'UTF-8')
        : strtolower($text);
}

/*
 * ФУНКЦИЯ compressImage() — сжимает фотографию после загрузки.
 * ЗАЧЕМ: фото с телефона бывают по 5-8 МБ, сайт грузится медленно.
 * Эта функция уменьшает фото до 1200 пикселей по большей стороне
 * и пересохраняет с качеством 82 — вес падает в десятки раз.
 * GIF не трогаем (там анимация, её сжатие сломало бы).
 * Если библиотека GD не включена — функция просто ничего не делает.
 */
function compressImage(string $path, string $extension): void
{
    if (!extension_loaded('gd')) {
        return;
    }

    // Открываем фото в памяти — «как» зависит от формата файла
    $image = match ($extension) {
        'jpg' => @imagecreatefromjpeg($path),
        'png' => @imagecreatefrompng($path),
        'webp' => @imagecreatefromwebp($path),
        default => null,
    };

    if (!$image) {
        return;
    }

    // Считаем размеры и решаем: надо ли уменьшать
    $width = imagesx($image);
    $height = imagesy($image);
    $maxSide = 1200;

    if ($width <= $maxSide && $height <= $maxSide) {
        $targetWidth = $width;
        $targetHeight = $height;
    } else {
        $ratio = min($maxSide / $width, $maxSide / $height);
        $targetWidth = (int)round($width * $ratio);
        $targetHeight = (int)round($height * $ratio);
    }

    // Готовим «чистый холст» нужного размера
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

    // PNG и WEBP умеют прозрачный фон — сохраняем его, чтобы не появились чёрные углы
    if (in_array($extension, ['png', 'webp'], true)) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    }

    // Переносим фото на холст (с уменьшением, если нужно)
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    // Сохраняем результат в тот же файл (перезаписываем «тяжёлое» фото лёгким)
    match ($extension) {
        'jpg' => imagejpeg($canvas, $path, 82),
        'png' => imagepng($canvas, $path, 6),
        'webp' => imagewebp($canvas, $path, 82),
        default => null,
    };

    // Убираем фото из памяти, чтобы не засорять сервер
    imagedestroy($image);
    imagedestroy($canvas);
}

/*
 * ФУНКЦИЯ loadProducts() — читает товары из файла database.txt.
 * Внутри файла товары лежат в формате JSON (просто длинный список).
 * Возвращает массив товаров: каждый товар = название, цена, фото и т.д.
 */
function loadProducts(string $file): array
{
    if (!file_exists($file)) {
        return [];   // файла ещё нет — значит товаров нет
    }

    $content = trim((string)file_get_contents($file));
    if ($content === '') {
        return [];   // файл пустой
    }

    // Пробуем прочитать как JSON — это текущий формат базы
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        // Дописываем каждому товару недостающие поля со значениями «по умолчанию»,
        // чтобы дальше в коде ничего не сломалось
        foreach ($decoded as &$product) {
            $product += [
                'id' => uniqid('product_', true),
                'sku' => '',          // АРТИКУЛ: короткий номер товара для админа
                                'qty' => '',          // КОЛИЧЕСТВО на складе, штук. Пусто = количество не ведётся
                                                                'buy_price' => '',    // закупочная цена за штуку — для калькулятора в «Складе»
                'title' => '',
                'price' => '0',
                'description' => '',
                'details' => '',
                'tag' => '',
                'category' => 'all',
                'stock' => '1',
                'views' => '0',
                'created' => 0,
            ];

            // Если у товара нет списка фото — смотрим старое поле image
            if (!isset($product['photos']) || !is_array($product['photos'])) {
                $product['photos'] = !empty($product['image'])
                    ? [$product['image']]
                    : [];
            }

            // Максимум 4 фото на товар
            $product['photos'] = array_slice($product['photos'], 0, 4);
        }
        unset($product);

        return array_values($decoded);
    }

    // Сюда попадаем, только если база в СТАРОМ формате (строки, разделённые ||).
    // Оставлено для совместимости — старые товары не потеряются.
    $products = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $data = explode('||', $line);
        if (count($data) < 6) {
            continue;
        }

        $products[] = [
            'id' => trim($data[0]),
            'sku' => '',
            'title' => html_entity_decode($data[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'price' => $data[2] ?? '0',
            'description' => html_entity_decode($data[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'details' => '',
            'tag' => html_entity_decode($data[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'photos' => !empty($data[5]) ? [$data[5]] : [],
            'category' => $data[6] ?? 'all',
            'stock' => $data[7] ?? '1',
            'views' => $data[9] ?? '0',
            'created' => isset($data[10]) ? (int)$data[10] : 0,
        ];
    }

    return $products;
}

/*
 * ФУНКЦИЯ saveProducts() — записывает все товары обратно в database.txt.
 * LOCK_EX — «замок»: если два заказа придут одновременно, файл не сломается.
 */
function saveProducts(string $file, array $products): bool
{
    $json = json_encode(
        array_values($products),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json !== false && file_put_contents($file, $json, LOCK_EX) !== false;
}

/*
 * ФУНКЦИЯ nextSku() — придумывает следующий свободный артикул.
 * Смотрит на все числовые артикулы товаров и берёт число на 1 больше
 * самого большого. Стартуем со 100, чтобы первый номер был «взрослым» (101).
 */
function nextSku(array $products): string
{
    $max = 100;

    foreach ($products as $product) {
        $sku = (string)($product['sku'] ?? '');

        // Учитываем только чисто числовые артикулы (318, 319…)
        if ($sku !== '' && ctype_digit($sku) && (int)$sku > $max) {
            $max = (int)$sku;
        }
    }

    return (string)($max + 1);
}

/*
 * ФУНКЦИЯ deleteUploadedPhotos() — удаляет файлы фотографий с диска.
 * Вызывается, когда товар удаляют или меняют ему фото, чтобы в папке uploads
 * не копился мусор. Проверка strpos защищает: удалять можно ТОЛЬКО файлы
 * внутри папки uploads, ничего другого.
 */
function deleteUploadedPhotos(array $photos, string $uploadDir): void
{
    $realUploadDir = realpath($uploadDir);
    if ($realUploadDir === false) {
        return;
    }

    foreach ($photos as $photo) {
        $realPhoto = realpath(__DIR__ . '/' . ltrim((string)$photo, '/'));

        if (
            $realPhoto !== false
            && strpos($realPhoto, $realUploadDir . DIRECTORY_SEPARATOR) === 0
            && is_file($realPhoto)
        ) {
            @unlink($realPhoto);
        }
    }
}

/*
 * --- ЗАПУСК: пароль, товары, счётчик корзины ---
 */

// Если файла pass.txt ещё нет — создаём его со стандартным паролем 1234.
// ВАЖНО: до переноса на хостинг поменяй пароль через админку!
if (!file_exists($password_file)) {
    file_put_contents($password_file, '1234', LOCK_EX);
}
$admin_password = trim((string)file_get_contents($password_file));   // читаем пароль

// Читаем все товары из базы — они понадобятся и витрине, и админке
$products = loadProducts($database_file);

// Считаем, сколько товаров лежит в корзине покупателя (для надписи «Корзина (2)»)
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $cartQty) {
        $cartCount += max(0, (int)$cartQty);
    }
}
// --- СЧЁТЧИК ПОСЕЩАЕМОСТИ: считаем заходы на сайт (админ не считается) ---
$visitors_file = __DIR__ . '/visitors-data.php';

$visitorData = ['total_views' => 0, 'days' => []];
if (is_file($visitors_file)) {
    // Первая строка файла — «замок» от любопытных, данные идут после неё
    $rawVisitors = (string)file_get_contents($visitors_file);
    $visitorBreak = strpos($rawVisitors, "\n");
    if ($visitorBreak !== false) {
        $decodedVisitors = json_decode(trim(substr($rawVisitors, $visitorBreak + 1)), true);
        if (is_array($decodedVisitors)) {
            $visitorData = array_merge(['total_views' => 0, 'days' => []], $decodedVisitors);
        }
    }
}

if (!isset($_SESSION['admin'])) {
    $todayKey = date('Y-m-d');
    if (!isset($visitorData['days'][$todayKey])) {
        $visitorData['days'][$todayKey] = ['views' => 0, 'uniques' => []];
    }

    // Уникальный посетитель = отпечаток (IP + браузер), хранится как хеш — обезличенно
    $visitorFingerprint = md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $visitorData['days'][$todayKey]['views']++;
    if (!in_array($visitorFingerprint, $visitorData['days'][$todayKey]['uniques'], true)) {
        $visitorData['days'][$todayKey]['uniques'][] = $visitorFingerprint;
    }
    $visitorData['total_views']++;

    // Храним только последние 60 дней, чтобы файл не рос бесконечно
    ksort($visitorData['days']);
    if (count($visitorData['days']) > 60) {
        $visitorData['days'] = array_slice($visitorData['days'], -60, null, true);
    }

    file_put_contents(
        $visitors_file,
        "<?php http_response_code(404); exit; ?>\n" . json_encode($visitorData, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// Переменные для сообщений на странице (ошибки и уведомления)
$login_error = '';
$form_error = '';
$pass_message = '';
$editing = null;   // сюда кладут товар, который админ сейчас редактирует

// --- ВЫХОД из админки: ссылка catalog.php?logout=1 ---
if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: catalog.php');
    exit;
}

// --- ВХОД в админку: админ отправил форму с паролем ---
if (isset($_POST['login'])) {
    $submittedPassword = (string)($_POST['password'] ?? '');

    // hash_equals сравнивает пароли безопасно, без «утечек» времени
    if (hash_equals($admin_password, $submittedPassword)) {
        $_SESSION['admin'] = true;               // запоминаем: это админ
        header('Location: catalog.php');
        exit;
    }
    $login_error = 'Неверный пароль.';
}

// --- СМЕНА ПАРОЛЯ: только если сейчас залогинен админ ---
if (isset($_POST['change_pass']) && isset($_SESSION['admin'])) {
    $oldPassword = trim((string)($_POST['old_password'] ?? ''));
    $newPassword = trim((string)($_POST['new_password'] ?? ''));

    if (hash_equals($admin_password, $oldPassword) && $newPassword !== '') {
        file_put_contents($password_file, $newPassword, LOCK_EX);   // записываем новый пароль в файл
        $admin_password = $newPassword;
        $pass_message = 'Пароль успешно изменён.';
    } else {
        $pass_message = 'Проверьте старый и новый пароль.';
    }
}

// --- БЫСТРОЕ ИЗМЕНЕНИЕ КОЛИЧЕСТВА: кнопка «OK» в списке «Склад» ---
if (isset($_POST['restock'], $_POST['restock_id']) && isset($_SESSION['admin'])) {
    $restockId = (string)$_POST['restock_id'];
    $restockQty = preg_replace('/[^0-9]/', '', (string)($_POST['restock_qty'] ?? ''));

    // Закупочная цена: разрешаем запятую как десятичный разделитель (250,50 → 250.50)
    $restockBuy = str_replace(',', '.', trim((string)($_POST['restock_buy'] ?? '')));
    $restockBuy = preg_replace('/[^0-9.]/', '', $restockBuy);

    foreach ($products as &$product) {
        if ((string)$product['id'] === $restockId) {
            $product['qty'] = $restockQty === '' ? '' : (int)$restockQty;
            $product['buy_price'] = $restockBuy === '' ? '' : $restockBuy;

            // Количество стало известно — «в наличии» теперь считается само
            if ($product['qty'] !== '') {
                $product['stock'] = (int)$product['qty'] > 0 ? '1' : '0';
            }
            break;
        }
    }
    unset($product);

    saveProducts($database_file, $products);
    header('Location: catalog.php#admin-stock');
    exit;
}

/*
 * --- ДОСКА ЗАДАЧ АДМИНА: план работ с галочками ---
 * Задания хранятся в tasks-data.php (первая строка — замок от любопытных).
 */
$tasks_file = __DIR__ . '/tasks-data.php';

function loadAdminTasks(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = (string)file_get_contents($file);
    $nl = strpos($raw, "\n");
    if ($nl === false) {
        return [];
    }
    $data = json_decode(trim(substr($raw, $nl + 1)), true);
    return is_array($data) ? array_values($data) : [];
}

function saveAdminTasks(string $file, array $tasks): void
{
    file_put_contents(
        $file,
        "<?php http_response_code(404); exit; ?>\n" . json_encode(array_values($tasks), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

if (isset($_SESSION['admin'])) {
    // Добавить задание
    if (isset($_POST['task_add'])) {
        $taskText = trim((string)($_POST['task_text'] ?? ''));
        if ($taskText !== '') {
            $tasks = loadAdminTasks($tasks_file);
            $tasks[] = [
                'id' => uniqid('task_', true),
                'text' => mb_substr($taskText, 0, 300),
                'done' => false,
                'created' => time(),
            ];
            saveAdminTasks($tasks_file, $tasks);
        }
        header('Location: catalog.php#admin-tasks');
        exit;
    }

    // Поставить/снять галочку
    if (isset($_POST['task_toggle'], $_POST['task_id'])) {
        $tasks = loadAdminTasks($tasks_file);
        foreach ($tasks as &$task) {
            if ((string)($task['id'] ?? '') === (string)$_POST['task_id']) {
                $task['done'] = empty($task['done']);
                break;
            }
        }
        unset($task);
        saveAdminTasks($tasks_file, $tasks);
        header('Location: catalog.php#admin-tasks');
        exit;
    }

    // Удалить задание
    if (isset($_POST['task_delete'], $_POST['task_id'])) {
        $tasks = loadAdminTasks($tasks_file);
        foreach ($tasks as $key => $task) {
            if ((string)($task['id'] ?? '') === (string)$_POST['task_id']) {
                unset($tasks[$key]);
                break;
            }
        }
        saveAdminTasks($tasks_file, $tasks);
        header('Location: catalog.php#admin-tasks');
        exit;
    }
}
/*
 * --- РАЗОВОЕ НАЗНАЧЕНИЕ АРТИКУЛОВ ---
 * Если у какого-то товара ещё нет артикула (старые товары) — придумываем
 * номера автоматически и сохраняем базу. Это происходит один раз:
 * на следующем заходе у всех товаров уже будут артикулы.
 * Выполняется только под админом, чтобы не трогать базу во время визитов покупателей.
 */
if (isset($_SESSION['admin'])) {
    $needsSku = false;

    foreach ($products as &$product) {
        if ((string)($product['sku'] ?? '') === '') {
            $product['sku'] = nextSku($products);
            $needsSku = true;
        }
    }
    unset($product);

    if ($needsSku) {
        saveProducts($database_file, $products);
        $products = loadProducts($database_file);   // перечитываем, чтобы всё было аккуратно
    }
}

// --- УДАЛЕНИЕ ТОВАРА: ссылка вида catalog.php?delete_id=... (только для админа) ---
if (isset($_GET['delete_id'], $_SESSION['admin'])) {
    $deleteId = (string)$_GET['delete_id'];

    // Ищем товар по id, удаляем его фото с диска и самого товар из списка
    foreach ($products as $key => $product) {
        if ((string)$product['id'] === $deleteId) {
            deleteUploadedPhotos($product['photos'] ?? [], $upload_dir);
            unset($products[$key]);
            break;
        }
    }

    saveProducts($database_file, $products);
    header('Location: catalog.php');
    exit;
}

// --- РЕДАКТИРОВАНИЕ: админ нажал «Редактировать» — находим товар, чтобы заполнить форму его данными ---
if (isset($_GET['edit_id'], $_SESSION['admin'])) {
    foreach ($products as $product) {
        if ((string)$product['id'] === (string)$_GET['edit_id']) {
            $editing = $product;
            break;
        }
    }
}

/*
 * --- СОХРАНЕНИЕ ТОВАРА (добавление нового ИЛИ правка существующего) ---
 * Админ нажал кнопку «Добавить товар» / «Сохранить изменения».
 * Это самый длинный блок: сначала проверки, потом загрузка фото, потом запись в базу.
 */
if (isset($_POST['save_product']) && isset($_SESSION['admin'])) {
    $isEdit = !empty($_POST['product_id']);   // если в форме спрятан id — значит редактируем, а не создаём
    $productId = $isEdit
        ? (string)$_POST['product_id']
        : uniqid('product_', true);           // для нового товара придумываем уникальный id

    $existing = null;        // прежняя версия товара (при редактировании)
    $existingIndex = null;   // под каким номером он лежит в списке

    if ($isEdit) {
        foreach ($products as $key => $product) {
            if ((string)$product['id'] === $productId) {
                $existing = $product;
                $existingIndex = $key;
                break;
            }
        }
    }

    // Читаем поля формы и чистим их от лишних пробелов
    $title = trim((string)($_POST['title'] ?? ''));
    $price = preg_replace('/[^0-9]/', '', (string)($_POST['price'] ?? ''));   // оставляем только цифры: «1 500 р.» -> «1500»
    $tag = trim((string)($_POST['tag'] ?? ''));
    $category = (string)($_POST['category'] ?? 'all');
    $description = trim((string)($_POST['description'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $stock = isset($_POST['stock']) ? '1' : '0';   // галочка «в наличии»: стоит = 1, нет = 0
        // КОЛИЧЕСТВО: можно оставить пустым. Если заполнено — «в наличии» вычисляется само
    $qtyRaw = trim((string)($_POST['qty'] ?? ''));
    $qtyDigits = preg_replace('/[^0-9]/', '', $qtyRaw);
    $qty = $qtyDigits === '' ? '' : max(0, (int)$qtyDigits);

    if ($qty !== '') {
        $stock = $qty > 0 ? '1' : '0';
    }

    /*
     * --- АРТИКУЛ ---
     * Админ может вписать свой номер (например, как на WB) или оставить
     * пустым — тогда номер придумается сам (101, 102…).
     * Убираем пробелы, ограничиваем 20 символами.
     */
    $sku = trim((string)($_POST['sku'] ?? ''));
    $sku = preg_replace('/\s+/u', '', $sku);
    if (mb_strlen($sku) > 20) {
        $sku = mb_substr($sku, 0, 20);
    }

    // Проверяем, что такой артикул не занят другим товаром
    if ($sku !== '') {
        foreach ($products as $product) {
            if (
                (string)($product['id'] ?? '') !== $productId
                && strcasecmp((string)($product['sku'] ?? ''), $sku) === 0
            ) {
                $form_error = 'Артикул «' . $sku . '» уже занят другим товаром. Придумайте другой.';
                break;
            }
        }
    }

    // Если категория какая-то левая — ставим «Разное» (защита от подделки формы)
    if (!in_array($category, ['gadgets', 'clothes', 'beauty', 'all'], true)) {
        $category = 'all';
    }

    $uploadedPhotos = [];

    // Проверяем форму: без названия и цены сохранять нельзя
    if ($title === '' || $price === '') {
        $form_error = 'Заполните название и цену товара.';
    } elseif (isset($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
        // --- ЗАГРУЗКА ФОТОГРАФИЙ ---
        // Выбираем индексы только тех файлов, которые человек действительно выбрал
        $selectedFiles = [];

        foreach ($_FILES['photos']['name'] as $index => $name) {
            if (($_FILES['photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $selectedFiles[] = $index;
            }
        }

        if (count($selectedFiles) > 4) {
            $form_error = 'Можно загрузить не больше четырёх фотографий.';
        } elseif ($selectedFiles && !is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            $form_error = 'Не удалось создать папку uploads.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);   // определяет РЕАЛЬНЫЙ тип файла, а не по имени
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            foreach ($selectedFiles as $index) {
                $error = $_FILES['photos']['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                $tmp = $_FILES['photos']['tmp_name'][$index] ?? '';
                $size = (int)($_FILES['photos']['size'][$index] ?? 0);

                if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    $form_error = 'Не удалось загрузить одну из фотографий.';
                    break;
                }
                if ($size > 8 * 1024 * 1024) {
                    $form_error = 'Размер одной фотографии не должен превышать 8 МБ.';
                    break;
                }

                // Проверяем реальный тип файла: переименованный вирус .jpg сюда не пройдёт
                $mime = $finfo->file($tmp);
                if (!isset($allowedTypes[$mime])) {
                    $form_error = 'Подойдут фотографии JPG, PNG, WEBP или GIF.';
                    break;
                }

                // Придумываем случайное имя файла, чтобы имена не совпадали
                $filename = bin2hex(random_bytes(12)) . '.' . $allowedTypes[$mime];
                if (!move_uploaded_file($tmp, $upload_dir . $filename)) {
                    $form_error = 'Не удалось сохранить фотографию.';
                    break;
                }
                               // Сжимаем фото сразу после сохранения — так сайт грузится быстрее
                compressImage($upload_dir . $filename, $allowedTypes[$mime]);

                $uploadedPhotos[] = 'uploads/' . $filename;
            }
        }
    }

    // Ошибок нет — сохраняем товар в базу
    if ($form_error === '') {
        $oldPhotos = $existing['photos'] ?? [];
        $clearPhotos = isset($_POST['clear_photos']);   // галочка «удалить текущие фото»

        // Какие фото будут у товара: новые, пустые или прежние
        if ($uploadedPhotos) {
            $photos = $uploadedPhotos;
        } elseif ($clearPhotos) {
            $photos = [];
        } else {
            $photos = $oldPhotos;
        }

        /*
         * АРТИКУЛ при сохранении:
         * - админ вписал номер — используем его;
         * - поле пустое — придумываем следующий свободный номер.
         */
        if ($sku !== '') {
            $finalSku = $sku;
        } else {
            $finalSku = nextSku($products);
        }

        // Собираем «паспорт» товара
        $newProduct = [
            'id' => $productId,
            'sku' => $finalSku,
            'qty' => $qty,
            'title' => $title,
            'price' => $price,
            'description' => $description,
            'details' => $details,
            'tag' => $tag,
            'photos' => array_slice($photos, 0, 4),
            'category' => $category,
            'stock' => $stock,
            'views' => $existing['views'] ?? '0',      // просмотры не сбрасываем при правке
            'created' => $existing ? (int)($existing['created'] ?? 0) : time(),   // дата «НОВИНКА» — только у нового товара
        ];

        if ($isEdit && $existingIndex !== null) {
            // Редактирование: заменяем старый товар новым
            $products[$existingIndex] = $newProduct;
            if ($uploadedPhotos || $clearPhotos) {
                deleteUploadedPhotos($oldPhotos, $upload_dir);   // старые фото больше не нужны
            }
        } else {
            // Новый товар: просто добавляем в конец списка
            $products[] = $newProduct;
        }

        if (saveProducts($database_file, $products)) {
            header('Location: catalog.php');   // перезагружаем страницу, чтобы форма очистилась
            exit;
        }

        // Не получилось записать базу — удаляем только что загруженные фото, чтобы не было мусора
        deleteUploadedPhotos($uploadedPhotos, $upload_dir);
        $form_error = 'Не удалось сохранить базу товаров.';
    }

    // Если была ошибка — возвращаем введённые данные в форму, чтобы человек не набирал заново
    if ($form_error !== '') {
        $editing = [
            'id' => $productId,
            'sku' => $sku,
            'qty' => $qtyRaw,
            'title' => $title,
            'price' => $price,
            'description' => $description,
            'details' => $details,
            'tag' => $tag,
            'photos' => $existing['photos'] ?? [],
            'category' => $category,
            'stock' => $stock,
        ];
    }
}

/*
 * --- ФИЛЬТР ПО КАТЕГОРИЯМ ---
 * Ссылки «catalog.php?cat=gadgets» и т.п. Показываем только товары выбранной категории.
 */
$filter = (string)($_GET['cat'] ?? 'all');
if (!in_array($filter, ['all', 'gadgets', 'clothes', 'beauty'], true)) {
    $filter = 'all';
}

// Красивые названия категорий (для показа покупателю)
$categoryNames = [
    'gadgets' => 'Гаджеты и шнуры',
    'clothes' => 'Одежда и вещи',
    'beauty' => 'Косметика',
    'all' => 'Разное',
];

// Отбираем товары для показа: все или только выбранная категория
$visibleProducts = array_values(array_filter($products, function ($product) use ($filter) {
    return $filter === 'all' || ($product['category'] ?? 'all') === $filter;
}));
?>
<!-- ============================================================
     ДАЛЬШЕ ИДЁТ ОБЫЧНАЯ СТРАНИЦА (HTML): шапка, товары, окно товара, чат
     ============================================================ -->
<!doctype html>
<html lang="ru">
<head>
<!-- НАСТРОЙКИ СТРАНИЦЫ ДЛЯ БРАУЗЕРА И ПОИСКОВИКОВ (SEO) -->
<!-- НАСТРОЙКИ СТРАНИЦЫ ДЛЯ БРАУЗЕРА И ПОИСКОВИКОВ (SEO) -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TRENDBOX — онлайн-магазин</title>
<link rel="icon" type="image/png" href="uploads/logo.png">
<meta name="description" content="Магазин TRENDBOX: гаджеты, одежда, красота. Корзина, быстрое оформление заказа без регистрации, отслеживание статуса заказа.">
<meta property="og:title" content="Каталог товаров — TRENDBOX">
<meta property="og:description" content="Магазин TRENDBOX: быстрый заказ, отслеживание статуса.">
<meta property="og:type" content="website">



<!-- ============================================================
     СТИЛИ ОФОРМЛЕНИЯ (CSS). Менять цвета можно в :root,
     остальное — аккуратно, блок за блоком.
     ============================================================ -->
<style>
/* Базовые цвета и отступы всего сайта */

:root{color-scheme:dark;--bg:#080c18;--panel:#121a2b;--panel2:#19243b;--text:#f8fafc;--muted:#9aa8c0;--line:rgba(255,255,255,.1);--accent:#b7a6ff;--mint:#6ee7d2;--green:#42dfaa;--radius:18px}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;flex-direction:column;color:var(--text);background:radial-gradient(ellipse at 15% 0%,rgba(107,75,210,.17),transparent 34rem),radial-gradient(ellipse at 95% 20%,rgba(45,170,170,.09),transparent 30rem),var(--bg);font:16px/1.55 Arial,sans-serif}a{color:inherit}button,input,textarea,select{font:inherit}.container{width:min(1160px,calc(100% - 36px));margin:auto}

/* Шапка сайта: логотип и кнопки справа */
.site-header{position:sticky;top:0;z-index:10;border-bottom:1px solid var(--line);background:rgba(8,12,24,.86);backdrop-filter:blur(18px)}.header-inner{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px;text-decoration:none;font-weight:900;letter-spacing:.09em}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;color:#111326;background:linear-gradient(135deg,var(--accent),var(--mint));font-size:19px}.header-actions{display:flex;gap:10px;align-items:center}

/* Обычные кнопки */
.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 15px;border:1px solid var(--line);border-radius:11px;color:var(--text);background:var(--panel2);text-decoration:none;cursor:pointer;transition:.2s}.button:hover{transform:translateY(-2px);filter:brightness(1.12)}.button-primary{color:#111326;border-color:transparent;background:linear-gradient(135deg,#e6dcff,#99f0df);font-weight:800}.button-danger{background:#7f1d3b;border-color:#9f3154}

/* Особенная кнопка «На главную» с бликом */
.button-home {
    position: relative;
    overflow: hidden;
    gap: 9px;
    padding: 11px 17px;
    border: 1px solid rgba(183, 166, 255, .35);
    color: #f8f6ff;
    background: linear-gradient(115deg, #302758, #193c43);
    box-shadow: 0 4px 16px rgba(112, 91, 190, .16);
    transition:
        transform .22s ease,
        box-shadow .22s ease,
        border-color .22s ease;
}

/* Мягкий блик пробегает по кнопке при наведении */
.button-home::before {
    content: "";
    position: absolute;
    top: -40%;
    left: -65%;
    width: 45%;
    height: 180%;
    background: rgba(255, 255, 255, .16);
    transform: skewX(-24deg);
    transition: left .55s ease;
    pointer-events: none;
}

.button-home:hover {
    transform: translateY(-2px);
    border-color: rgba(183, 166, 255, .75);
    box-shadow: 0 8px 24px rgba(112, 91, 190, .28);
}

.button-home:hover::before {
    left: 130%;
}

/* Квадратик с иконкой дома внутри кнопки */
.home-icon {
    display: inline-grid;
    place-items: center;
    width: 25px;
    height: 25px;
    border-radius: 8px;
    color: #17152a;
    background: linear-gradient(135deg, #e6dcff, #99f0df);
    font-size: 19px;
    transition: transform .22s ease;
}

.button-home:hover .home-icon {
    transform: rotate(-8deg) scale(1.1);
}

/* Уважаем тех, у кого анимации в системе выключены */
@media (prefers-reduced-motion: reduce) {
    .button-home,
    .button-home::before,
    .home-icon {
        transition: none;
    }
}

/* Большая приветственная шапка «Каталог товаров» и красные сообщения об ошибках */
main{flex:1;padding:32px 0 48px}.hero{position:relative;overflow:hidden;margin-bottom:26px;padding:clamp(24px,5vw,44px);border:1px solid var(--line);border-radius:24px;background:linear-gradient(115deg,rgba(48,39,88,.78),rgba(19,35,53,.78))}.hero-kicker{color:var(--mint);font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase}.hero h1{margin:8px 0;font-size:clamp(28px,5vw,44px);line-height:1.12}.hero p{margin:0;color:#c2cbe0}
.notice{margin-bottom:18px;padding:13px 16px;border:1px solid rgba(251,113,133,.42);border-radius:12px;color:#fecdd3;background:rgba(159,18,57,.18)}.admin-box{margin-bottom:22px;padding:clamp(18px,3vw,26px);border:1px solid rgba(183,166,255,.22);border-radius:20px;background:linear-gradient(135deg,rgba(91,66,160,.13),transparent 55%),rgba(18,26,43,.94)}.admin-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.admin-heading h2{margin:0;font-size:20px}.admin-label{padding:5px 9px;border:1px solid rgba(183,166,255,.25);border-radius:999px;color:var(--accent);font-size:12px}

/* ==== ДИЗАЙН ПАНЕЛИ АДМИНА: шапка-баннер, меню, две колонки ==== */
/* Круглые живые часы на баннере панели */
.admin-clock{position:relative;z-index:2;margin-left:auto;flex:0 0 auto}
.admin-clock-face{position:relative;width:112px;height:112px;border-radius:50%;border:2px solid rgba(255,255,255,.35);background:radial-gradient(circle at 35% 30%,rgba(255,255,255,.22),rgba(0,0,0,.35));box-shadow:0 10px 30px rgba(0,0,0,.45),inset 0 0 18px rgba(255,255,255,.08)}
.admin-clock-hand{position:absolute;left:50%;bottom:50%;transform-origin:50% 100%;border-radius:4px}
.admin-clock-h{width:5px;height:26px;margin-left:-2.5px;background:#f3ecff}
.admin-clock-m{width:4px;height:38px;margin-left:-2px;background:#d9ccff}
.admin-clock-s{width:2px;height:46px;margin-left:-1px;background:var(--mint)}
.admin-clock-dot{position:absolute;left:50%;top:50%;width:10px;height:10px;margin:-5px 0 0 -5px;border-radius:50%;background:#fff;box-shadow:0 0 8px rgba(255,255,255,.7)}
@media(max-width:560px){.admin-clock{display:none}}

.admin-shell{display:flex;flex-direction:column;gap:18px}
.admin-banner{position:relative;overflow:hidden;display:flex;align-items:center;padding:clamp(22px,4vw,36px);border:1px solid rgba(255,255,255,.16);border-radius:24px;background:linear-gradient(120deg,#2a1a5e 0%,#4b1d6e 45%,#8a2f5a 100%)}
.admin-banner::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(115deg,rgba(255,255,255,.05) 0 2px,transparent 2px 26px)}
.admin-banner-img{position:absolute;top:0;right:0;bottom:0;height:100%;width:48%;object-fit:cover;opacity:.9;-webkit-mask-image:linear-gradient(90deg,transparent,#000 45%);mask-image:linear-gradient(90deg,transparent,#000 45%)}
.admin-banner-text{position:relative;max-width:62%}
.admin-banner-text h2{margin:0;font-size:clamp(22px,3.4vw,30px)}
.admin-banner-text p{margin:8px 0 0;color:#e9dff6;font-size:14px}
.admin-banner-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.admin-banner-chips span{padding:6px 12px;border:1px solid rgba(255,255,255,.3);border-radius:999px;background:rgba(0,0,0,.25);font-size:13px;font-weight:700}
.admin-quicknav{display:flex;gap:9px;flex-wrap:wrap}
.admin-quicknav .button{padding:10px 14px}
.admin-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
@media(max-width:900px){.admin-grid2{grid-template-columns:1fr}}
@media(max-width:560px){.admin-banner-img{width:100%;opacity:.3}.admin-banner-text{max-width:100%}}

/* Счётчики, мини-график и красная точка новых заказов */
.admin-spark{display:flex;align-items:center;gap:10px;margin-top:12px}
.admin-spark-label{color:#e9dff6;font-size:12px}
.new-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;margin-left:7px;border-radius:999px;color:#fff;background:#f43f5e;font-size:12px;font-weight:800;animation:badge-pulse 1.6s infinite}
@keyframes badge-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.18)}}

/* Угол панели: бейдж «Администратор» над часами + анимация баннера + конфетти */
.admin-corner{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;gap:14px;margin-left:auto;padding-top:18px}
.admin-founder{display:flex;flex-direction:column;align-items:center;gap:6px}
.admin-founder-avatar{display:grid;place-items:center;width:58px;height:58px;border-radius:50%;font-size:28px;background:radial-gradient(circle at 35% 30%,rgba(255,255,255,.3),rgba(0,0,0,.35));border:2px solid rgba(255,255,255,.35);box-shadow:0 8px 24px rgba(0,0,0,.4)}
.admin-founder-name{padding:4px 10px;border:1px solid rgba(255,255,255,.3);border-radius:999px;background:rgba(0,0,0,.28);color:#f3ecff;font-size:12px;font-weight:700}
.admin-banner .admin-clock{margin-left:0}
.admin-banner{background:linear-gradient(120deg,#2a1a5e,#4b1d6e,#8a2f5a,#4b1d6e,#2a1a5e);background-size:300% 300%;animation:banner-flow 18s ease infinite}
@keyframes banner-flow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
.confetti-piece{position:fixed;top:-14px;width:9px;height:14px;border-radius:2px;z-index:9999;pointer-events:none;animation:confetti-fall linear forwards}
@keyframes confetti-fall{to{transform:translateY(105vh) rotate(540deg)}}
@media(max-width:560px){.admin-corner{display:none}}

/* Формы админки: поля ввода, подписи, галочки */
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{min-width:0;display:flex;flex-direction:column;gap:7px}.field-wide{grid-column:1/-1}.field label{color:#c5cee1;font-size:13px;font-weight:700}input,textarea,select{width:100%;min-width:0;padding:12px 13px;color:var(--text);border:1px solid rgba(255,255,255,.12);border-radius:11px;outline:none;background:#0c1221}input:focus,textarea:focus,select:focus{border-color:var(--accent)}textarea{min-height:90px;resize:vertical}.help{color:var(--muted);font-size:13px}.current-photos{display:flex;flex-wrap:wrap;gap:9px}.current-photos img{width:76px;height:76px;object-fit:cover;border-radius:10px}.check-row{display:flex;align-items:center;gap:9px}.check-row input{width:auto}

/* Поиск и кнопки-фильтры категорий */
.search{margin:0 0 16px;background:rgba(18,26,43,.92)}.filters{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:22px}.filter{padding:8px 14px;color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:999px;background:var(--panel)}.filter.active{color:#111326;background:linear-gradient(135deg,#e6dcff,#99f0df);font-weight:800}

/* Сетка товаров: карточки с фото, ценой, метками */
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:19px}.card{min-width:0;overflow:hidden;display:flex;flex-direction:column;border:1px solid var(--line);border-radius:var(--radius);background:linear-gradient(160deg,rgba(26,36,58,.96),rgba(15,22,37,.98));cursor:pointer;transition:.2s}.card:hover{transform:translateY(-4px);border-color:rgba(183,166,255,.35)}.card.out{opacity:.68}.card-image{height:245px;position:relative;background:#0d1423}.card-image img{width:100%;height:100%;display:block;object-fit:cover}.no-photo{height:100%;display:grid;place-items:center;color:var(--muted)}.badge{position:absolute;top:12px;left:12px;padding:5px 10px;border-radius:999px;color:white;background:#9f1239;font-size:11px;font-weight:800}.badge-new{left:auto;right:12px;color:#221606;background:linear-gradient(135deg,#ffe7a3,#ffbd72)}

/* Плашка артикула: подсвеченный номер товара. Видна ТОЛЬКО админу */
.sku-chip{display:inline-block;padding:4px 10px;border:1px solid rgba(110,231,210,.45);border-radius:999px;color:var(--mint);background:rgba(110,231,210,.09);font-size:12px;font-weight:800;white-space:nowrap;letter-spacing:.04em}

/* Список «Склад»: строки «артикул + товар + количество» */
.stock-list{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.stock-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.stock-row:last-child{border-bottom:none}
.stock-title{flex:1;min-width:160px;font-size:14px;overflow-wrap:anywhere}
.stock-qty{min-width:110px;color:var(--muted);font-size:13px;text-align:right}
.stock-form{display:flex;gap:6px;align-items:center}
.stock-form input{width:86px;padding:7px 8px;text-align:center}
.stock-form .button{padding:7px 10px;font-size:13px}

/* Внутренность карточки: метка, название, описание, цена, кнопки */
.card-body{display:flex;flex-direction:column;flex:1;padding:17px}.tag{color:var(--mint);font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.card h3{margin:5px 0 7px;font-size:18px;overflow-wrap:anywhere}.short-description{margin:0 0 16px;color:var(--muted);font-size:14px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}.card-bottom{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:auto}.price{font-size:20px;font-weight:900;white-space:nowrap}.admin-links{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}.admin-links .button{padding:7px 10px;font-size:13px}

/* Подвал сайта и скрытая форма входа админа */
.site-footer{padding:22px 0;border-top:1px solid var(--line);background:rgba(8,12,24,.72)}.footer-inner{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px}.footer-note{color:var(--muted);font-size:13px}.secret-entry{opacity:.48;cursor:default;user-select:none}.login-form{display:none;align-items:center;gap:8px}.login-form.visible{display:flex}.login-form input{width:175px}

/* Большое окно товара: галерея слева, описание справа */
.modal{display:none;position:fixed;inset:0;z-index:30;padding:18px;background:rgba(0,0,0,.8);align-items:center;justify-content:center;backdrop-filter:blur(7px)}.modal.open{display:flex}.modal-content{position:relative;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);width:min(940px,100%);max-height:92vh;overflow:auto;border:1px solid var(--line);border-radius:22px;background:#121a2b;box-shadow:0 24px 90px rgba(0,0,0,.5)}
.modal-gallery{position:relative;min-height:390px;display:grid;place-items:center;background:#0a101d;overflow:hidden}.modal-gallery img{display:block;width:100%;height:100%;max-height:620px;object-fit:contain}.gallery-arrow{z-index:5;display:grid;place-items:center;position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border:1px solid rgba(255,255,255,.2);border-radius:50%;color:white;background:rgba(0,0,0,.72);cursor:pointer;font-size:27px}.gallery-arrow[hidden]{display:none}.gallery-prev{left:12px}.gallery-next{right:12px}.gallery-count{position:absolute;bottom:13px;left:50%;transform:translateX(-50%);padding:5px 11px;border-radius:999px;color:white;background:rgba(0,0,0,.7);font-size:12px}.modal-info{padding:clamp(22px,4vw,36px);overflow-wrap:anywhere}.modal-info h2{margin:6px 0;font-size:clamp(23px,3vw,30px)}.modal-price{margin:12px 0 20px;color:var(--green);font-size:25px;font-weight:900}.info-section{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}.info-section h3{margin:0 0 8px;color:var(--accent);font-size:12px;letter-spacing:.1em;text-transform:uppercase}.info-text{white-space:pre-wrap;color:#d7deeb;overflow-wrap:anywhere}.close-modal{position:absolute;top:10px;right:10px;z-index:7;width:40px;height:40px;border:1px solid rgba(255,255,255,.12);border-radius:50%;color:white;background:rgba(0,0,0,.72);cursor:pointer;font-size:24px}

/* Оценки (звёзды) в окне товара */
.rating-summary{margin-bottom:5px;color:var(--muted);font-size:14px}.rating-stars{display:flex;gap:5px}.rating-stars button{padding:0;border:0;color:#596477;background:transparent;cursor:pointer;font-size:30px;line-height:1.2}.rating-stars button.active{color:#ffc85c}

/* Чат в админке: список диалогов + переписка */
.admin-chat{display:grid;grid-template-columns:minmax(140px,.75fr) minmax(0,1.5fr);gap:12px;min-height:320px}.admin-chat-list{overflow:auto;max-height:420px;border:1px solid var(--line);border-radius:12px}.admin-chat-user{width:100%;padding:11px;display:block;text-align:left;color:var(--text);background:transparent;border:0;border-bottom:1px solid var(--line);cursor:pointer;overflow-wrap:anywhere}.admin-chat-user.active{background:rgba(183,166,255,.15)}.admin-chat-user small{display:block;margin-top:3px;color:var(--muted)}.admin-chat-area{min-width:0;display:flex;flex-direction:column}.admin-chat-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}.admin-chat-messages{flex:1;min-height:220px;max-height:360px;overflow:auto;display:flex;flex-direction:column;gap:9px;padding:11px;border:1px solid var(--line);border-radius:12px;background:rgba(8,12,24,.5)}.admin-chat-compose{display:flex;gap:8px;margin-top:9px}.admin-chat-compose textarea{min-height:44px}

/* Плавающая кнопка чата и окно чата для покупателей */
.chat-button{position:fixed;right:20px;bottom:20px;z-index:40;width:58px;height:58px;display:grid;place-items:center;border:0;border-radius:50%;color:#111326;background:linear-gradient(135deg,#e6dcff,#99f0df);box-shadow:0 10px 35px #0006;font-size:24px;cursor:pointer}.chat-window{display:none;position:fixed;right:20px;bottom:90px;z-index:40;width:min(360px,calc(100vw - 24px));height:min(500px,calc(100vh - 120px));overflow:hidden;flex-direction:column;border:1px solid var(--line);border-radius:18px;background:#121a2b;box-shadow:0 20px 65px #0009}.chat-window.open{display:flex}.chat-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:linear-gradient(120deg,#302758,#13353a)}.chat-head small{display:block;color:#d0d7e5}.chat-close{border:0;color:white;background:transparent;font-size:24px;cursor:pointer}.chat-name{margin:10px 12px 0;width:calc(100% - 24px)}.chat-messages{flex:1;overflow:auto;display:flex;flex-direction:column;gap:9px;padding:12px}.chat-message{max-width:88%;padding:9px 11px;border-radius:13px;white-space:pre-wrap;overflow-wrap:anywhere}.chat-message.visitor{align-self:flex-end;color:#111326;background:linear-gradient(135deg,#e6dcff,#99f0df)}.chat-message.admin{align-self:flex-start;background:#202c44}.chat-time{display:block;margin-top:4px;opacity:.65;font-size:10px}.chat-compose{display:flex;gap:8px;padding:10px;border-top:1px solid var(--line)}.chat-compose textarea{min-height:42px;max-height:100px}.chat-compose button{flex:none}

/* Как сайт выглядит на планшете */
@media(max-width:850px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.modal-content{grid-template-columns:1fr}.modal-gallery{min-height:300px;max-height:45vh}.modal-gallery img{max-height:45vh}}
/* Мини-калькулятор склада: вложено и выхлоп по каждому товару */
.stock-row{flex-wrap:wrap}
.stock-calc{font-size:13px;color:var(--muted);white-space:nowrap}
.stock-total{margin-top:12px;padding:12px 14px;border:1px dashed rgba(183,166,255,.35);border-radius:12px;font-size:14px}
/* Справочный центр администратора */
.help-modal{width:min(720px,92vw);max-height:88vh;padding:0;border:1px solid var(--line);border-radius:var(--radius);background:linear-gradient(160deg,rgba(26,36,58,.98),rgba(10,15,26,.99));color:#e9edf8;overflow:hidden}
.help-modal::backdrop{background:rgba(4,7,14,.75)}
.help-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line)}
.help-head h2{margin:0;font-size:19px}
.help-body{padding:20px 22px;overflow-y:auto;max-height:calc(88vh - 78px);font-size:14px;line-height:1.65}
.help-body h3{margin:18px 0 6px;font-size:15px;color:var(--accent)}
.help-body h3:first-child{margin-top:0}
.help-body ul{margin:4px 0 8px 18px;padding:0}
.help-body li{margin:4px 0}
.help-warn{margin-top:18px;padding:12px 16px;border:1px dashed rgba(110,231,210,.45);border-radius:12px}

/* Посещаемость: карточки с цифрами и мини-график за 7 дней */
.traffic-cards{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin:14px 0}
.traffic-card{display:flex;flex-direction:column;gap:3px;padding:12px 10px;border:1px solid var(--line);border-radius:12px;background:rgba(15,22,37,.6);text-align:center}
.traffic-num{font-size:22px;font-weight:800;color:var(--accent)}
.traffic-num-mint{color:var(--mint)}
.traffic-cap{font-size:11px;color:var(--muted)}
.traffic-chart{display:flex;align-items:flex-end;gap:8px;height:110px;padding:10px 6px 0;border-top:1px solid var(--line)}
.traffic-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:6px}
.traffic-bar{width:100%;max-width:34px;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,var(--accent),rgba(183,166,255,.25))}
.traffic-bar-day{font-size:10px;color:var(--muted)}
@media(max-width:560px){.traffic-cards{grid-template-columns:repeat(3,1fr)}.traffic-chart{gap:5px}}

/* Доска задач: деревянная рамка, зелёное полотно, надписи «мелом» */
.task-board{background:linear-gradient(160deg,#9a6a35,#6e4420);padding:12px;border-radius:var(--radius);box-shadow:inset 0 0 0 2px rgba(0,0,0,.25)}
.task-board-canvas{background:radial-gradient(120% 100% at 25% 15%,rgba(255,255,255,.06),transparent 55%),linear-gradient(160deg,#20453a,#142a22);border:2px dashed rgba(255,255,255,.16);border-radius:12px;padding:18px 18px 16px;font-family:'Segoe Print','Marker Felt','Comic Sans MS',cursive}
.task-board-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap}
.task-board-head h2{margin:0;font-size:20px;color:#fdf6e3;text-shadow:0 0 7px rgba(255,255,255,.28)}
.task-board-label{font-size:12px;color:rgba(253,246,227,.75)}
.task-add-form{display:flex;gap:8px;margin:14px 0}
.task-add-form input[type=text]{flex:1;min-width:0;padding:10px 12px;border:1px dashed rgba(255,255,255,.35);border-radius:10px;background:rgba(255,255,255,.06);color:#fdf6e3;font-family:inherit;font-size:14px}
.task-add-form input[type=text]::placeholder{color:rgba(253,246,227,.45)}
.task-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.task-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;background:rgba(255,255,255,.05);border:1px dashed rgba(255,255,255,.12)}
.task-item.done .task-text{text-decoration:line-through;opacity:.55}
.task-check{width:26px;height:26px;border:2px solid rgba(253,246,227,.7);border-radius:7px;background:transparent;color:#fdf6e3;font-size:15px;cursor:pointer;line-height:1;flex-shrink:0;padding:0}
.task-item.done .task-check{background:#6ee7d2;border-color:#6ee7d2;color:#142a22}
.task-text{flex:1;min-width:0;color:#fdf6e3;font-size:14px;word-break:break-word}
.task-del{width:24px;height:24px;border:none;border-radius:8px;background:transparent;color:rgba(253,246,227,.55);font-size:18px;cursor:pointer;flex-shrink:0;padding:0}
.task-del:hover{color:#ff8fb0}
.task-empty{color:rgba(253,246,227,.7);font-size:13px;margin:8px 0 0}
.task-board .button-primary{white-space:nowrap}
@media(max-width:560px){.task-add-form{flex-direction:column}}

/* Выдвижная школьная доска-памятка */
.memo-tab{position:fixed;right:0;top:40%;z-index:60;writing-mode:vertical-rl;padding:16px 9px;border:none;border-radius:10px 0 0 10px;background:linear-gradient(180deg,#2f6d52,#1d4a38);color:#f3f7f2;font-size:14px;font-weight:700;letter-spacing:1px;box-shadow:-3px 3px 10px rgba(0,0,0,.35);cursor:pointer}
.memo-tab:hover{filter:brightness(1.12)}
.memo-drawer{position:fixed;top:0;right:-460px;width:min(430px,94vw);height:100vh;z-index:70;transition:right .35s ease;background:linear-gradient(90deg,#6d431e,#9a6530 20%,#6d431e);box-shadow:-8px 0 30px rgba(0,0,0,.45)}
.memo-drawer.open{right:0}
.memo-frame{height:calc(100% - 28px);margin:14px 8px;border-radius:14px;border:7px solid #7a4a21;background:linear-gradient(160deg,#2a5443,#1b382c);box-shadow:inset 0 0 40px rgba(0,0,0,.5);display:flex;flex-direction:column}
.memo-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px dashed rgba(255,255,255,.25)}
.memo-title{color:#f2f6ef;font-weight:800;flex:1}
.memo-saved{color:#a8e6c9;font-size:12px}
.memo-text{flex:1;resize:none;border:none;outline:none;background:transparent;color:#f2f6ef;font-size:17px;line-height:1.8;padding:16px;font-family:'Segoe Print','Comic Sans MS',cursive;caret-color:#a8e6c9}
.memo-foot{padding:10px 16px;color:rgba(242,246,239,.65);font-size:12px;border-top:1px dashed rgba(255,255,255,.25)}
/* Быстрое меню: вертикальная рейка слева — неон, свечение, анимации */
@media(min-width:901px){
    @keyframes railIn{
        from{opacity:0;transform:translate(-40px,-50%)}
        to{opacity:1;transform:translate(0,-50%)}
    }
    /* «Заказы» всегда мягко пульсирует — чтобы не забывать проверять */
@keyframes ordersPulse{
    0%,100%{box-shadow:0 0 0 0 rgba(110,231,210,.4)}
    50%{box-shadow:0 0 0 9px rgba(110,231,210,0)}
}
.admin-quicknav a[href="orders.php"]{animation:ordersPulse 2.2s ease-in-out infinite}

/* Когда есть новые заказы — счётчик подмигивает поярче */
.admin-quicknav .new-badge{animation:badgeBlink 1.1s ease-in-out infinite}
@keyframes badgeBlink{
    0%,100%{filter:brightness(1)}
    50%{filter:brightness(1.4)}
}
    .admin-quicknav{
        position:fixed;left:18px;top:50%;transform:translateY(-50%);z-index:55;
        flex-direction:column;gap:7px;padding:12px 10px;border-radius:20px;
        background:rgba(12,17,30,.78);backdrop-filter:blur(10px);
        border:1px solid rgba(183,166,255,.28);
        box-shadow:0 12px 40px rgba(0,0,0,.45);
        animation:railIn .55s ease both;
    }
    /* Неоновый ободок: градиентная рамка поверх стекла */
    .admin-quicknav::before{
        content:'';position:absolute;inset:-1px;border-radius:20px;padding:1px;
        background:linear-gradient(160deg,rgba(183,166,255,.6),rgba(110,231,210,.3) 55%,rgba(183,166,255,0));
        -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);
        -webkit-mask-composite:xor;mask-composite:exclude;
        pointer-events:none;
    }
    .admin-quicknav .button{
        position:relative;padding:9px 14px 9px 16px;font-size:13px;text-align:left;
        white-space:nowrap;border-radius:12px;overflow:hidden;
        transition:transform .18s ease,box-shadow .18s ease,color .18s ease;
    }
    /* Светящаяся полоска слева — появляется при наведении */
    .admin-quicknav .button::before{
        content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:3px;
        background:linear-gradient(180deg,#b7a6ff,#6ee7d2);
        opacity:0;transform:scaleY(.3);transition:.2s ease;
    }
    /* Мягкая подсветка, разливающаяся по кнопке */
    .admin-quicknav .button::after{
        content:'';position:absolute;inset:0;pointer-events:none;
        background:linear-gradient(100deg,rgba(183,166,255,.2),rgba(110,231,210,.12) 60%,transparent);
        opacity:0;transition:opacity .2s ease;
    }
    .admin-quicknav .button:hover{
        transform:translateX(6px);color:#fff;
        box-shadow:0 6px 18px rgba(110,231,210,.18);
    }
    .admin-quicknav .button:hover::before{opacity:1;transform:scaleY(1)}
    .admin-quicknav .button:hover::after{opacity:1}
    .admin-quicknav .button:active{transform:translateX(6px) scale(.96)}
}
/* Логотип TRENDBOX в шапке */
.brand-logo{width:36px;height:36px;border-radius:10px;object-fit:cover;display:block}
/* Фирменная надпись TRENDBOX в шапке: градиент фиолетовый → мятный */
.brand-name{background:linear-gradient(90deg,#b7a6ff,#6ee7d2);-webkit-background-clip:text;background-clip:text;color:transparent;font-weight:900;letter-spacing:2px;font-size:19px}



/* Маркет-стиль: цена-герой и метка «В наличии» */
.card .price{font-size:21px;font-weight:900;letter-spacing:-.4px}
.in-stock-chip{position:absolute;top:12px;left:12px;padding:5px 11px;border-radius:999px;background:rgba(13,80,66,.85);color:var(--mint);font-size:11px;font-weight:800;border:1px solid rgba(110,231,210,.35);backdrop-filter:blur(4px)}

/* Как сайт выглядит на телефоне */
@media(max-width:560px){.container{width:calc(100% - 24px)}.header-inner{min-height:66px}.brand-mark{width:34px;height:34px}.header-actions .button{padding:9px 10px;font-size:13px}main{padding-top:21px}.hero{padding:24px 20px;border-radius:19px}.grid{grid-template-columns:1fr;gap:14px}.card{display:grid;grid-template-columns:40% minmax(0,1fr);min-height:180px}.card-image{height:100%;min-height:180px}.card-body{padding:13px}.card h3{font-size:16px}.short-description{-webkit-line-clamp:2;font-size:13px;margin-bottom:10px}.price{font-size:17px}.card-bottom{align-items:flex-start;flex-direction:column;gap:8px}.form-grid{grid-template-columns:1fr}.field-wide{grid-column:auto}.admin-heading{align-items:flex-start;flex-direction:column}.modal{padding:8px}.modal-content{max-height:96vh;border-radius:17px}.modal-gallery{min-height:260px;max-height:42vh}.modal-gallery img{max-height:42vh}.gallery-arrow{display:grid;place-items:center;z-index:5}.modal-info{padding:20px}.footer-inner{align-items:flex-start;flex-direction:column}.login-form{width:100%}.login-form input{flex:1;min-width:0}.admin-chat{grid-template-columns:1fr}.admin-chat-list{max-height:145px}.admin-chat-toolbar{align-items:flex-start;flex-direction:column}.chat-button{right:14px;bottom:14px;width:54px;height:54px}.chat-window{right:12px;bottom:78px;height:min(470px,calc(100vh - 100px))}} 
/* Мобильный баланс: страница листается только вниз, вбок ничего не уезжает */
/* Прятать подписи на телефоне — только иконки */
@media(max-width:560px){
    .hide-mobile{display:none}
}
@media(max-width:560px){
    html,body{max-width:100%;overflow-x:hidden}
    /* Шапка: у «На главную» и «Отследить заказ» остаются только иконки */
    .header-inner{gap:8px}
    .header-actions{gap:6px}
    .header-actions .button{padding:9px 10px;font-size:12px}
    .button-home span:last-child,
    .header-actions .button a[href*="status"] {display:none}
    .header-actions .button:nth-child(2){display:none} /* убирает подпись, оставляет иконку — см. правку 2 */
    .brand span:last-child{font-size:15px}

    /* Быстрое меню админки: кнопки переносятся на новые строки, не уезжают вбок */
    .admin-quicknav{gap:7px}
    .admin-quicknav .button{padding:9px 11px;font-size:13px}

    /* Счётчики баннера панели: переносятся строкой */
    .admin-banner-chips{gap:6px}
    .admin-banner-chips span{font-size:12px;padding:5px 10px}

    /* Поля ввода не разъезжают экран (телефонный зум на телефоне) */
    input,select,textarea{font-size:16px}
}

/* Мобильные правки: плашки на карточке, растянутые фото, вход в админку */
@media(max-width:560px){
    /* «НОВИНКА» переезжает в нижний правый угол фото — не слипается с «Нет в наличии» */
    .card-image .badge{top:8px;left:8px}
    .card-image .badge-new{top:auto;bottom:8px;left:auto;right:8px}

    /* Фото не растягивает карточку вниз: максимум 265px, лишнее аккуратно обрезается */
    .card-image{height:auto;min-height:180px;max-height:265px}
    .card-image img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}

    /* Подвал поднимается выше кнопки чата — поле пароля и «Войти» не перекрываются */
    .site-footer{padding-bottom:88px}
}

/* Строка диалога в админке: кнопка покупателя + корзинка удаления */
.admin-chat-row {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--line);
}

.admin-chat-row .admin-chat-user {
    flex: 1;
    min-width: 0;
    border: 0;
}

.admin-chat-delete {
    flex: 0 0 42px;
    border: 0;
    color: #fda4af;
    background: transparent;
    cursor: pointer;
    font-size: 18px;
}

.admin-chat-delete:hover {
    color: white;
    background: #9f1239;
}
</style>
</head>

<body>

<!-- ================= ШАПКА САЙТА: логотип и кнопки ================= -->
<header class="site-header">
    <div class="container header-inner">
                                <a class="brand" href="catalog.php"><span class="brand-mark">T</span><span class="brand-name">TRENDBOX</span></a>
        <div class="header-actions">
                        <a class="button button-home" href="index.html"><span class="home-icon">⌂</span><span class="hide-mobile">На главную</span></a>
            <!-- Кнопка корзины: если в корзине есть товары, показываем их количество -->
            <a class="button" href="cart.php">🛒 Корзина<?php if ($cartCount > 0): ?> (<?= (int)$cartCount ?>)<?php endif; ?></a>
                                <a class="button" href="status.php"><span class="hide-mobile">📦 </span>Заказ</a>
            
        </div>
    </div>
</header>

<main class="container">
    <!-- Большая приветственная шапка -->
    <section class="hero">
        <div class="hero-kicker">Выбирайте с удовольствием</div>
        <h1>Каталог товаров</h1>
        <p>Откройте товар, чтобы посмотреть фотографии и подробную информацию.</p>
    </section>

    <!-- Красные сообщения: ошибка формы товара / ошибка входа / результат смены пароля -->
    <?php foreach ([$form_error, $login_error, $pass_message] as $message): ?>
        <?php if ($message !== ''): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <?php endforeach; ?>

    <!-- ============================================================
         ПАНЕЛЬ АДМИНИСТРАТОРА — видна ТОЛЬКО когда админ вошёл
         ============================================================ -->
            <?php if (isset($_SESSION['admin'])): ?>
                        <?php
        // --- СТАТИСТИКА ДЛЯ ШАПКИ ПАНЕЛИ: заказы, выручка, график 7 дней ---
        $orders_file = __DIR__ . '/orders-data.php';

        function loadAdminOrders(string $file): array
        {
            if (!is_file($file)) {
                return [];
            }

            $content = (string)file_get_contents($file);
            $newline = strpos($content, "\n");

            if ($newline === false) {
                return [];
            }

            $data = json_decode(substr($content, $newline + 1), true);

            return is_array($data) ? $data : [];
        }

        $adminOrders = loadAdminOrders($orders_file);
        $ordersToday = 0;
        $ordersNew = 0;
        $ordersTotal = count($adminOrders);
        $revenueTotal = 0;

        foreach ($adminOrders as $oneOrder) {
            if (strpos((string)($oneOrder['date'] ?? ''), date('Y-m-d')) === 0) {
                $ordersToday++;
            }
            if (($oneOrder['status'] ?? '') === 'new') {
                $ordersNew++;
            }
            $revenueTotal += (int)($oneOrder['total'] ?? 0);
        }

        // Выручка по дням за последние 7 дней — для мини-графика
        $weekRevenue = [];
        for ($i = 6; $i >= 0; $i--) {
            $weekRevenue[date('Y-m-d', strtotime("-$i day"))] = 0;
        }

        foreach ($adminOrders as $oneOrder) {
            $dayKey = substr((string)($oneOrder['date'] ?? ''), 0, 10);
            if (isset($weekRevenue[$dayKey])) {
                $weekRevenue[$dayKey] += (int)($oneOrder['total'] ?? 0);
            }
        }

        $weekMax = max($weekRevenue);
        $sparkPoints = '';
        $sparkIndex = 0;

        foreach ($weekRevenue as $dayRevenue) {
            $x = $sparkIndex * 20;
            $y = 40 - ($weekMax > 0 ? round($dayRevenue / $weekMax * 36) : 0);
            $sparkPoints .= ($sparkIndex === 0 ? '' : ' ') . $x . ',' . $y;
            $sparkIndex++;
        }
        ?>
        <!-- Обёртка всей панели: шапка-баннер + меню + блоки -->
        <div class="admin-shell">

            <!-- Шапка панели с картинкой. Если файла uploads/admin-banner.jpg нет — остаётся просто градиент -->
            <div class="admin-banner">
                                <?php
                // Ищем баннер в любом из форматов — jpg, png, webp
                $adminBannerSrc = '';
                foreach (['admin-banner.jpg', 'admin-banner.jpeg', 'admin-banner.png', 'admin-banner.webp'] as $bannerName) {
                    if (is_file($upload_dir . $bannerName)) {
                        $adminBannerSrc = 'uploads/' . $bannerName;
                        break;
                    }
                }
                ?>
                <?php if ($adminBannerSrc !== ''): ?>
                    <img class="admin-banner-img" src="<?= e($adminBannerSrc) ?>" alt="">
                <?php endif; ?>
                <div class="admin-banner-text">
                    <h2>⚙️ Панель управления</h2>
                    <p>Здесь ты управляешь магазином: товары, склад, заказы и сообщения покупателей.</p>
                                       <div class="admin-banner-chips">
                        <span>💰 Заказов сегодня: <?= $ordersToday ?></span>
                        <span>📦 Всего заказов: <?= $ordersTotal ?></span>
                        <span>💵 Выручка: <?= number_format($revenueTotal, 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div class="admin-spark">
                        <svg width="130" height="46" viewBox="0 0 120 40" fill="none" aria-hidden="true">
                            <polyline points="<?= e($sparkPoints) ?>" fill="none" stroke="#6ee7d2" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="admin-spark-label">Продажи за 7 дней</span>
                    </div>
                </div>
                                <!-- Угол панели: бейдж «Администратор» над часами -->
                <div class="admin-corner">
                    <div class="admin-founder">
                        <span class="admin-founder-avatar">🧑‍💻</span>
                        <span class="admin-founder-name">Администратор</span>
                    </div>

                <!-- Живые круглые часы: стрелки показывают реальное время -->
                <div class="admin-clock" aria-hidden="true">
                    <div class="admin-clock-face">
                        <span class="admin-clock-hand admin-clock-h" id="clock-h"></span>
                        <span class="admin-clock-hand admin-clock-m" id="clock-m"></span>
                        <span class="admin-clock-hand admin-clock-s" id="clock-s"></span>
                        <span class="admin-clock-dot"></span>
                    </div>
                </div>
                <script>
                (function () {
                    var h = document.getElementById('clock-h'),
                        m = document.getElementById('clock-m'),
                        s = document.getElementById('clock-s');
                    if (!h || !m || !s) return;
                    function tick() {
                        var now = new Date(),
                            hh = now.getHours() % 12,
                            mm = now.getMinutes(),
                            ss = now.getSeconds();
                        h.style.transform = 'rotate(' + (hh * 30 + mm * 0.5) + 'deg)';
                        m.style.transform = 'rotate(' + (mm * 6) + 'deg)';
                        s.style.transform = 'rotate(' + (ss * 6) + 'deg)';
                    }
                    tick();
                    setInterval(tick, 1000);
                })();
                </script>
                </div>
                                <script>
                // Конфетти при входе в админку — раз за открытие браузера
                (function () {
                    try {
                        if (sessionStorage.getItem('confettiShown')) return;
                        sessionStorage.setItem('confettiShown', '1');
                    } catch (e) { return; }

                    var colors = ['#b7a6ff', '#6ee7d2', '#ffd98a', '#ff8fb0', '#ffffff'];

                    for (var i = 0; i < 60; i++) {
                        (function (n) {
                            setTimeout(function () {
                                var p = document.createElement('span');
                                p.className = 'confetti-piece';
                                p.style.left = Math.random() * 100 + 'vw';
                                p.style.background = colors[n % colors.length];
                                p.style.animationDuration = (2.4 + Math.random() * 1.8) + 's';
                                document.body.appendChild(p);
                                setTimeout(function () { p.remove(); }, 4600);
                            }, n * 22);
                        })(i);
                    }
                })();
                </script>
            </div>
            <!-- Посещаемость сайта: цифры и график за неделю -->
            <?php
            $visToday = $visitorData['days'][date('Y-m-d')] ?? ['views' => 0, 'uniques' => []];
            $visYesterday = $visitorData['days'][date('Y-m-d', strtotime('-1 day'))] ?? ['views' => 0, 'uniques' => []];
            $visUniqueToday = count($visToday['uniques'] ?? []);
            $vis7 = 0;
            $vis30 = 0;
            for ($i = 0; $i < 30; $i++) {
                $daySum = (int)($visitorData['days'][date('Y-m-d', strtotime("-$i day"))]['views'] ?? 0);
                if ($i < 7) { $vis7 += $daySum; }
                $vis30 += $daySum;
            }
            // График: заходы по дням за последние 7 дней
            $visChart = [];
            $visChartMax = 0;
            for ($i = 6; $i >= 0; $i--) {
                $dayKey = date('Y-m-d', strtotime("-$i day"));
                $dayViews = (int)($visitorData['days'][$dayKey]['views'] ?? 0);
                $visChart[$dayKey] = $dayViews;
                $visChartMax = max($visChartMax, $dayViews);
            }
            ?>
            <section class="admin-box traffic-box">
                <div class="admin-heading">
                    <h2>👥 Посещаемость сайта</h2>
                    <span class="admin-label">Трафик</span>
                </div>
                <div class="traffic-cards">
                    <div class="traffic-card"><span class="traffic-num"><?= (int)($visToday['views'] ?? 0) ?></span><span class="traffic-cap">заходов сегодня</span></div>
                    <div class="traffic-card"><span class="traffic-num traffic-num-mint"><?= $visUniqueToday ?></span><span class="traffic-cap">уникальных сегодня</span></div>
                    <div class="traffic-card"><span class="traffic-num"><?= (int)($visYesterday['views'] ?? 0) ?></span><span class="traffic-cap">вчера</span></div>
                    <div class="traffic-card"><span class="traffic-num"><?= $vis7 ?></span><span class="traffic-cap">за 7 дней</span></div>
                    <div class="traffic-card"><span class="traffic-num"><?= $vis30 ?></span><span class="traffic-cap">за 30 дней</span></div>
                    <div class="traffic-card"><span class="traffic-num"><?= (int)($visitorData['total_views'] ?? 0) ?></span><span class="traffic-cap">всего заходов</span></div>
                </div>
                <div class="traffic-chart">
                    <?php foreach ($visChart as $dayKey => $dayViews): ?>
                        <div class="traffic-bar-wrap" title="<?= e($dayKey) ?>: <?= $dayViews ?> заходов">
                            <span class="traffic-bar" style="height:<?= $visChartMax > 0 ? max(6, (int)round($dayViews / $visChartMax * 100)) : 6 ?>%"></span>
                            <span class="traffic-bar-day"><?= e(date('d.m', strtotime($dayKey))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="help" style="margin-top:10px">Администратор в статистике не учитывается. Уникальные посетители считаются по обезличенному отпечатку (IP + браузер) — персональные данные не хранятся.</p>
            </section>

            <!-- Доска задач: школьная доска, куда админ пишет план работ -->
            <?php $adminTasks = loadAdminTasks($tasks_file); ?>
            <section class="admin-box task-board" id="admin-tasks">
                <div class="task-board-canvas">
                    <div class="task-board-head">
                        <h2>🧑‍🏫 Доска задач</h2>
                        <span class="task-board-label"><?= count(array_filter($adminTasks, fn($t) => !empty($t['done']))) ?> из <?= count($adminTasks) ?> выполнено</span>
                    </div>
                    <form method="post" action="catalog.php" class="task-add-form">
                        <input type="hidden" name="task_add" value="1">
                        <input type="text" name="task_text" maxlength="300" placeholder="Напиши задание… например «Загрузить 5 товаров с фото»" required>
                        <button class="button button-primary" type="submit">+ Добавить</button>
                    </form>
                    <?php if (!$adminTasks): ?>
                        <p class="task-empty">Пока чисто. Записывай шаги — выполняй по одному и ставь галочку ✔</p>
                    <?php else: ?>
                        <ul class="task-list">
                            <?php foreach ($adminTasks as $task): ?>
                                <li class="task-item <?= !empty($task['done']) ? 'done' : '' ?>">
                                    <form method="post" action="catalog.php" class="task-check-form">
                                        <input type="hidden" name="task_toggle" value="1">
                                        <input type="hidden" name="task_id" value="<?= e((string)($task['id'] ?? '')) ?>">
                                        <button class="task-check" type="submit" title="Отметить/снять галочку"><?= !empty($task['done']) ? '✔' : '' ?></button>
                                    </form>
                                    <span class="task-text"><?= e((string)($task['text'] ?? '')) ?></span>
                                    <form method="post" action="catalog.php" class="task-del-form">
                                        <input type="hidden" name="task_delete" value="1">
                                        <input type="hidden" name="task_id" value="<?= e((string)($task['id'] ?? '')) ?>">
                                        <button class="task-del" type="submit" title="Удалить">×</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
<?php
// Загружаем сохранённый текст доски-памятки
$notesFile = __DIR__ . '/notes-data.php';
$adminNote = '';
if (is_file($notesFile)) {
    $rawNote = (string)file_get_contents($notesFile);
    $nlNote = strpos($rawNote, "\n");
    if ($nlNote !== false) {
        $noteData = json_decode(trim(substr($rawNote, $nlNote + 1)), true);
        if (is_array($noteData)) {
            $adminNote = (string)($noteData['text'] ?? '');
        }
    }
}
?>
<!-- Выдвижная доска-памятка: язычок справа открывает и закрывает -->
<button type="button" class="memo-tab" onclick="memoToggle()">🧑‍🏫 Памятка</button>
<div class="memo-drawer" id="memo-drawer">
    <div class="memo-frame">
        <div class="memo-head">
            <span class="memo-title">🧑‍🏫 Памятка администратора</span>
            <span class="memo-saved" id="memo-saved"></span>
            <button type="button" class="button" onclick="memoToggle()">Скрыть ✕</button>
        </div>
        <textarea id="memo-text" class="memo-text" spellcheck="false" placeholder="Пиши здесь всё, что нельзя забыть: задания по шагам, закупки, звонки, идеи…&#10;&#10;Сохраняется само, можно писать сколь угодно много."><?= e($adminNote) ?></textarea>
        <div class="memo-foot">Пишется мелом · сохраняется автоматически ✍️</div>
    </div>
</div>
<script>
(function () {
    var drawer = document.getElementById('memo-drawer');
    var area = document.getElementById('memo-text');
    var saved = document.getElementById('memo-saved');
    if (!drawer || !area || !saved) return;

    var timer = null;

    window.memoToggle = function () {
        drawer.classList.toggle('open');
        if (drawer.classList.contains('open')) {
            area.focus();
        }
    };

    function memoSave() {
        saved.textContent = '…сохраняем';
        fetch('notes-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: area.value })
        }).then(function (r) { return r.text(); }).then(function (t) {
            saved.textContent = (t === 'ok') ? '✓ сохранено' : '✗ не сохранилось';
            setTimeout(function () { saved.textContent = ''; }, 2500);
        }).catch(function () {
            saved.textContent = '✗ нет связи с сервером';
        });
    }

    // Автосохранение: через секунду после того, как перестал печатать
    area.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(memoSave, 900);
    });
})();
</script>
            <!-- Быстрое меню панели -->
            <nav class="admin-quicknav">
                <a class="button" href="#admin-form">✦ Товары</a>
                <a class="button" href="#admin-stock">📋 Склад</a>
                                <a class="button" href="#admin-tasks">🧑‍🏫 Доска</a>
                <a class="button" href="#admin-support">💬 Сообщения</a>
                                <a class="button" href="orders.php">📦 Заказы<?php if ($ordersNew > 0): ?><span class="new-badge"><?= $ordersNew ?></span><?php endif; ?></a>
                <a class="button" href="analytics.php">📊 Аналитика</a>
                    <a class="button" href="#" onclick="document.getElementById('help-modal').showModal();return false;">📖 Инструкция</a>
                <a class="button button-danger" href="catalog.php?logout=1">🚪 Выход</a>
            </nav>
        <!-- Форма добавления/редактирования товара -->
        <section class="admin-box" id="admin-form">
            <div class="admin-heading">
                <h2><?= $editing ? '✦ Редактировать товар' : '✦ Добавить товар' ?></h2>
                <span class="admin-label">Панель управления</span>
            </div>

            <!-- enctype="multipart/form-data" обязателен, иначе фото не загрузятся -->
            <form action="catalog.php" method="post" enctype="multipart/form-data">
                <?php if ($editing): ?><input type="hidden" name="product_id" value="<?= e($editing['id']) ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field"><label for="title">Название товара *</label><input id="title" name="title" required value="<?= e($editing['title'] ?? '') ?>"></div>
                    <div class="field"><label for="price">Цена, ₽ *</label><input id="price" name="price" inputmode="numeric" required value="<?= e($editing['price'] ?? '') ?>"></div>
                    <div class="field">
                        <label for="sku">Артикул (номер товара)</label>
                        <input id="sku" name="sku" maxlength="20" placeholder="Например: 318" value="<?= e($editing['sku'] ?? '') ?>">
                        <span class="help">Твой номер для учёта (сайт и WB). Оставь пустым — присвоим сами.</span>
                    </div>
                                        <div class="field">
                        <label for="qty">Количество на складе, шт.</label>
                        <input id="qty" name="qty" inputmode="numeric" placeholder="Например: 12" value="<?= e((string)($editing['qty'] ?? '')) ?>">
                        <span class="help">Можно не заполнять. Если заполнено — «в наличии» считается само: больше 0 = в наличии, 0 = нет.</span>
                    </div>
                    <div class="field"><label for="tag">Метка, например «Скидка»</label><input id="tag" name="tag" value="<?= e($editing['tag'] ?? '') ?>"></div>
                    <div class="field">
                        <label for="category">Категория</label>
                        <select id="category" name="category">
                            <option value="gadgets" <?= ($editing['category'] ?? '') === 'gadgets' ? 'selected' : '' ?>>Гаджеты и шнуры</option>
                            <option value="clothes" <?= ($editing['category'] ?? '') === 'clothes' ? 'selected' : '' ?>>Одежда и вещи</option>
                            <option value="beauty" <?= ($editing['category'] ?? '') === 'beauty' ? 'selected' : '' ?>>Косметика</option>
                            <option value="all" <?= ($editing['category'] ?? 'all') === 'all' ? 'selected' : '' ?>>Разное</option>
                        </select>
                    </div>
                    <div class="field field-wide"><label for="description">Краткое описание</label><textarea id="description" name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea></div>
                    <div class="field field-wide"><label for="details">Подробная информация</label><textarea id="details" name="details" rows="7" placeholder="Характеристики, комплектация, размеры, материалы…"><?= e($editing['details'] ?? '') ?></textarea><span class="help">Подробности видны после открытия карточки.</span></div>
                    <div class="field field-wide"><label for="photos">Фотографии — выберите до 4 штук</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple><span class="help">JPG, PNG, WEBP или GIF, до 8 МБ на фото. Новые фотографии заменят старые.</span></div>

                    <!-- При редактировании показываем, какие фото уже есть, и галочку их удаления -->
                    <?php if (!empty($editing['photos'])): ?>
                        <div class="field field-wide">
                            <label>Текущие фотографии</label>
                            <div class="current-photos"><?php foreach ($editing['photos'] as $photo): ?><img src="<?= e($photo) ?>" alt="Фото товара"><?php endforeach; ?></div>
                            <label class="check-row"><input type="checkbox" name="clear_photos" value="1"> Удалить текущие фотографии, если новые не загружаются</label>
                        </div>
                    <?php endif; ?>

                    <div class="field field-wide"><label class="check-row"><input type="checkbox" name="stock" value="1" <?= ($editing['stock'] ?? '1') === '1' ? 'checked' : '' ?>> Товар в наличии</label></div>
                    <div class="field field-wide"><button class="button button-primary" type="submit" name="save_product"><?= $editing ? 'Сохранить изменения' : 'Добавить товар' ?></button></div>
                </div>
            </form>
        </section>
                <!-- Эти два блока стоят рядом в две колонки -->
        <div class="admin-grid2">
        <!-- Форма смены пароля админа -->
        <section class="admin-box">
            <div class="admin-heading"><h2>🔐 Сменить пароль</h2><span class="admin-label">Доступ администратора</span></div>
            <form action="catalog.php" method="post" class="form-grid">
                <div class="field"><label>Старый пароль</label><input type="password" name="old_password" required></div>
                <div class="field"><label>Новый пароль</label><input type="password" name="new_password" required></div>
                <div class="field field-wide"><button class="button" type="submit" name="change_pass">Сменить пароль</button></div>
            </form>
        </section>
                 <!-- Кнопки перехода к заказам и аналитике -->
        <section class="admin-box">
            <div class="admin-heading">
                <h2>📦 Заказы покупателей</h2>
                <span class="admin-label">Управление заказами</span>
            </div>
            <p style="margin:0;display:flex;gap:10px;flex-wrap:wrap">
    <a class="button button-primary" href="orders.php">📦 Заказы</a>
    <a class="button" href="analytics.php">📊 Аналитика</a>
</p>
        </section>

                </div>
        <!-- Список «Склад»: все товары с артикулами и количеством.
             Кнопка «OK» быстро меняет количество: пришёл товар — добавили штук -->
               <section class="admin-box" id="admin-stock">
            <div class="admin-heading">
                <h2>📋 Склад: товары и количество</h2>
                <span class="admin-label">Учёт остатков</span>
            </div>
            <p class="help" style="margin-bottom:12px">
                Впишешь количество и закупочную цену за штуку — склад посчитает, сколько денег вложено
                в остаток и какой выхлоп будет с полной продажи партии. Когда количество станет 0 —
                товар сам пометится «Нет в наличии». Пустое поле = наличие определяется галочкой в форме товара.
            </p>
            <?php if (!$products): ?>
                <p class="help">Товаров пока нет — добавьте первый товар в форме выше.</p>
            <?php else: ?>
                <div class="stock-list">
                    <?php
                    $totalInvested = 0.0;   // сколько денег стоит весь остаток склада (по закупке)
                    $totalProfit = 0.0;     // выхлоп с полной продажи всех остатков
                    foreach ($products as $product):
                        $qty = ($product['qty'] ?? '') === '' ? null : (int)$product['qty'];
                        $buy = (float)str_replace(',', '.', (string)($product['buy_price'] ?? ''));
                        $sell = (float)str_replace(',', '.', (string)($product['price'] ?? '0'));
                        $invested = ($qty !== null && $buy > 0) ? $buy * $qty : null;
                        $profit = ($qty !== null && $buy > 0 && $sell > 0) ? ($sell - $buy) * $qty : null;
                        if ($invested !== null) { $totalInvested += $invested; }
                        if ($profit !== null) { $totalProfit += $profit; }
                    ?>
                        <div class="stock-row">
                            <?php if ((string)($product['sku'] ?? '') !== ''): ?>
                                <span class="sku-chip">№ <?= e($product['sku']) ?></span>
                            <?php endif; ?>
                            <span class="stock-title"><?= e($product['title'] ?? '') ?></span>
                            <span class="stock-qty">
                                <?= $qty === null ? 'количество не ведётся' : ($qty . ' шт.') ?>
                            </span>
                            <form method="post" action="catalog.php" class="stock-form">
                                <input type="hidden" name="restock" value="1">
                                <input type="hidden" name="restock_id" value="<?= e($product['id']) ?>">
                                <input type="number" name="restock_qty" min="0" max="999999" value="<?= e((string)($product['qty'] ?? '')) ?>" placeholder="шт." title="Количество на складе">
                                <input type="number" name="restock_buy" min="0" step="0.01" value="<?= e((string)($product['buy_price'] ?? '')) ?>" placeholder="закуп" title="Закупочная цена за штуку">
                                <button class="button" type="submit">OK</button>
                            </form>
                            <span class="stock-calc">
                                <?php if ($invested !== null): ?>
                                    вложено <?= number_format($invested, 0, ',', ' ') ?> ₽
                                <?php endif; ?>
                                <?php if ($profit !== null): ?>
                                    · прибыль <?= number_format($profit, 0, ',', ' ') ?> ₽ <?= $profit >= 0 ? '🟢' : '🔴' ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($totalInvested > 0 || $totalProfit != 0): ?>
                        <div class="stock-total">
                            Итого по складу: вложено <b><?= number_format($totalInvested, 0, ',', ' ') ?> ₽</b>
                            · выхлоп при полной продаже <b><?= number_format($totalProfit, 0, ',', ' ') ?> ₽</b>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <!-- Справочный центр администратора: открывается кнопкой «Инструкция» -->
        <dialog id="help-modal" class="help-modal">
            <div class="help-head">
                <h2>📖 Инструкция администратора</h2>
                <button class="button" type="button" onclick="document.getElementById('help-modal').close()">Закрыть</button>
            </div>
            <div class="help-body">
                <h3>1. Вход и безопасность</h3>
                <ul>
                    <li>Форма входа открывается пятью нажатиями по копирайту в подвале сайта.</li>
                    <li>Пароль меняется в разделе «🔐 Сменить пароль». Делайте это регулярно и не используйте простые пароли.</li>
                    <li>Не оставляйте админ-панель открытой на чужих устройствах. Выход — ссылка «Выйти».</li>
                </ul>

                <h3>2. Товары</h3>
                <ul>
                    <li>Товар добавляется в форме «✦ Добавить товар»: название, цена, описание, категория, до 4 фото.</li>
                    <li>Фото: JPG, PNG, WEBP или GIF до 8 МБ. Сайт сам сжимает их для быстрой загрузки.</li>
                    <li>Артикул присваивается автоматически (101, 102…) и виден только вам, не покупателям.</li>
                    <li>Галочка «Товар в наличии» включает и выключает кнопку «В корзину».</li>
                    <li>«Редактировать» — правка карточки; «Удалить» — товар и его фото удаляются безвозвратно.</li>
                </ul>

                <h3>3. Склад и учёт</h3>
                <ul>
                    <li>Впишите количество штук — при нуле товар сам пометится «Нет в наличии».</li>
                    <li>Поле «закуп» — закупочная цена за штуку. Склад считает вложенные средства и прибыль по остатку, а также итог по всему складу.</li>
                </ul>

                <h3>4. Заказы и оплаты</h3>
                <ul>
                    <li>Новые заказы видны в разделе «📦 Заказы» и приходят уведомлением в ВК.</li>
                    <li>Оплата проходит через ЮKassa; статус «оплачено» фиксируется автоматически.</li>
                    <li>Меняйте статус заказа вовремя — покупатель видит его в «Статус заказа» по номеру заказа.</li>
                </ul>

                <h3>5. Аналитика, чат, отзывы</h3>
                <ul>
                    <li>«📊 Аналитика» — просмотры и популярные товары.</li>
                    <li>«Сообщения покупателей» — чат поддержки: отвечайте на обращения в течение дня.</li>
                    <li>Отзывы и оценки покупателей — в разделе рейтингов.</li>
                </ul>

                <div class="help-warn">
                    <h3>⚖️ Ответственность администратора</h3>
                    <ul>
                        <li><b>Достоверность информации.</b> Цены, характеристики и наличие товаров должны быть точными и актуальными (ст. 10 Закона РФ «О защите прав потребителей»).</li>
                        <li><b>Персональные данные.</b> Имена, телефоны и адреса покупателей используйте только для исполнения заказа. Публиковать и передавать третьим лицам запрещено (152-ФЗ «О персональных данных»).</li>
                        <li><b>Платежи.</b> При приёме онлайн-платежей соблюдайте условия договора с платёжным сервисом; вопросы применения контрольно-кассовой техники (54-ФЗ) решите до начала реальных продаж.</li>
                        <li><b>Резервные копии.</b> Регулярно сохраняйте копии файлов database.txt, orders-data.php и папки uploads — это ваша страховка от потери данных.</li>
                        <li>Данная памятка носит справочный характер и не заменяет консультацию юриста.</li>
                    </ul>
                </div>
            </div>
        </dialog>

        <!-- Чат поддержки (вид администратора): все диалоги с покупателями -->
        <section class="admin-box" id="admin-support">
            <div class="admin-heading"><h2>💬 Сообщения покупателей</h2><span class="admin-label">Чат поддержки</span></div>
            <div class="admin-chat">
                <div class="admin-chat-list" id="admin-chat-list"><div class="help" style="padding:12px">Загрузка диалогов…</div></div>
                <div class="admin-chat-area">
                    <div class="admin-chat-toolbar">
                        <div class="help" id="admin-chat-title">Выберите покупателя</div>
                        <button class="button button-danger" id="clear-chat" type="button" disabled>Очистить диалог</button>
                    </div>
                    <div class="admin-chat-messages" id="admin-chat-messages"></div>
                    <form class="admin-chat-compose" id="admin-chat-form">
                        <textarea id="admin-chat-input" placeholder="Напишите ответ…" maxlength="2000" required></textarea>
                        <button class="button button-primary" type="submit">Ответить</button>
                    </form>
                </div>
            </div>
        </section>
        </div>
    <?php endif; ?>

    <!-- ================= ВИТРИНА: поиск, фильтры, товары ================= -->

    <!-- Строка поиска: работает без перезагрузки, фильтрует карточки по тексту.
         Артикул тоже участвует в поиске — под админом можно найти товар по номеру -->
    <input id="search" class="search" type="search" placeholder="Найти товар по названию или описанию">
    <nav class="filters" aria-label="Категории">
        <a class="filter <?= $filter === 'all' ? 'active' : '' ?>" href="catalog.php?cat=all">Все товары</a>
        <a class="filter <?= $filter === 'gadgets' ? 'active' : '' ?>" href="catalog.php?cat=gadgets">Гаджеты</a>
        <a class="filter <?= $filter === 'clothes' ? 'active' : '' ?>" href="catalog.php?cat=clothes">Одежда</a>
        <a class="filter <?= $filter === 'beauty' ? 'active' : '' ?>" href="catalog.php?cat=beauty">Косметика</a>
    </nav>

    <!-- Сетка карточек товаров -->
    <section class="grid" id="products">
        <?php foreach ($visibleProducts as $product): ?>
            <?php
                // Готовим данные одной карточки
                $photos = array_slice($product['photos'] ?? [], 0, 4);
                $firstPhoto = $photos[0] ?? '';
                $inStock = ($product['stock'] ?? '1') === '1';
                // Текст для поиска: название + описание + подробности + метка + АРТИКУЛ, всё в нижнем регистре
                                $searchText = lowerText(($product['title'] ?? '') . ' ' . ($product['description'] ?? '') . ' ' . ($product['details'] ?? '') . ' ' . ($product['tag'] ?? '') . ' ' . ($product['sku'] ?? ''));
                // «НОВИНКА» — товар добавлен менее 3 дней назад (259200 секунд = 3 суток)
                $created = (int)($product['created'] ?? 0);
                $isNew = $created > 0 && $created <= time() && time() - $created < 259200;
                // Данные для всплывающего окна товара — кладём их прямо в карточку (data-product)
                $modalData = [
                    'id' => (string)$product['id'],
                    'sku' => (string)($product['sku'] ?? ''),
                    'title' => $product['title'] ?? '',
                    'price' => number_format((int)($product['price'] ?? 0), 0, '.', ' ') . ' ₽',
                    'description' => $product['description'] ?? '',
                    'details' => $product['details'] ?? '',
                    'tag' => !empty($product['tag']) ? $product['tag'] : 'Товар',
                    'category' => $categoryNames[$product['category'] ?? 'all'] ?? 'Разное',
                    'photos' => $photos,
                ];
            ?>
            <!-- Одна карточка товара. data-search — для поиска, data-product — для окна товара -->
            <article class="card <?= $inStock ? '' : 'out' ?>" data-search="<?= e($searchText) ?>" data-product="<?= e(json_encode($modalData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
                <div class="card-image">
                    <?php if ($firstPhoto !== ''): ?><img src="<?= e($firstPhoto) ?>" alt="<?= e($product['title']) ?>" loading="lazy"><?php else: ?><div class="no-photo">Фото скоро появится</div><?php endif; ?>
                    <?php if ($inStock): ?><span class="in-stock-chip">✓ В наличии</span><?php else: ?><span class="badge">Нет в наличии</span><?php endif; ?>
                    <?php if ($isNew): ?><span class="badge badge-new">НОВИНКА</span><?php endif; ?>
                </div>
                <div class="card-body">
                    <span class="tag"><?= e($product['tag'] ?: 'Товар') ?></span>
                    <!-- Плашка с артикулом: видна ТОЛЬКО админу. Покупатель её не увидит -->
                    <?php if (isset($_SESSION['admin']) && (string)($product['sku'] ?? '') !== ''): ?>
                        <div style="margin-top:7px"><span class="sku-chip">№ <?= e($product['sku']) ?></span></div>
                    <?php endif; ?>
                    <h3><?= e($product['title']) ?></h3>
                    <p class="short-description"><?= e($product['description'] ?? '') ?></p>
                    <div class="card-bottom">
                        <span class="price"><?= number_format((int)($product['price'] ?? 0), 0, '.', ' ') ?> ₽</span>
                                                <!-- Кнопка «В корзину»: добавляет товар по ссылке и не открывает окно товара -->
                        <?php if ($inStock): ?>
                            <a class="button" href="cart.php?add=<?= e($product['id']) ?>" onclick="event.stopPropagation()">В корзину</a>
                        <?php endif; ?>
                        <!-- Кнопка Telegram: появляется только если в настройках вписан $telegram_username -->
                        <?php if ($inStock && $telegram_username !== ''):
                            $message = 'Здравствуйте! Хочу заказать: ' . ($product['title'] ?? '') . ' — ' . number_format((int)($product['price'] ?? 0), 0, '.', ' ') . ' ₽';
                            $orderUrl = 'https://t.me/' . rawurlencode($telegram_username) . '?text=' . rawurlencode($message);
                        ?>
                            <a class="button button-primary" href="<?= e($orderUrl) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Заказать</a>
                        <?php endif; ?>
                    </div>
                    <!-- Кнопки админа на карточке: редактировать / удалить -->
                    <?php if (isset($_SESSION['admin'])): ?>
                        <div class="admin-links" onclick="event.stopPropagation()">
                            <a class="button" href="catalog.php?edit_id=<?= e($product['id']) ?>#admin-form">Редактировать</a>
                            <a class="button button-danger" href="catalog.php?delete_id=<?= e($product['id']) ?>" onclick="return confirm('Удалить товар?')">Удалить</a>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <p id="no-search-results" class="help" hidden>По вашему запросу товары не найдены.</p>
    <?php if (!$visibleProducts): ?><p class="help">В этой категории пока нет товаров.</p><?php endif; ?>
</main>

<!-- ================= ОКНО ТОВАРА (открывается по клику на карточку) ================= -->
<div class="modal" id="product-modal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-label="Карточка товара">
        <button class="close-modal" type="button" aria-label="Закрыть" onclick="closeModal()">×</button>
        <div class="modal-gallery">
            <img id="modal-photo" src="" alt="">
            <button class="gallery-arrow gallery-prev" id="gallery-prev" type="button" aria-label="Предыдущее фото">‹</button>
            <button class="gallery-arrow gallery-next" id="gallery-next" type="button" aria-label="Следующее фото">›</button>
            <span class="gallery-count" id="gallery-count"></span>
        </div>
        <div class="modal-info">
            <span class="tag" id="modal-tag"></span>
            <div class="help" id="modal-category"></div>
            <!-- Плашка артикула в окне товара: существует только под админом -->
            <?php if (isset($_SESSION['admin'])): ?><div style="margin-top:8px"><span class="sku-chip" id="modal-sku" hidden></span></div><?php endif; ?>
            <h2 id="modal-title"></h2>
            <div class="modal-price" id="modal-price"></div>
            <button class="button button-primary" id="modal-cart" type="button">В корзину</button>

            <!-- Оценки товара (звёзды) -->
            <section class="info-section">
                <h3>Оценка покупателей</h3>
                <div class="rating-summary" id="rating-summary">Загрузка оценок…</div>
                <div class="rating-stars" id="rating-stars">
                    <button type="button" data-score="1" aria-label="1 звезда">★</button>
                    <button type="button" data-score="2" aria-label="2 звезды">★</button>
                    <button type="button" data-score="3" aria-label="3 звезды">★</button>
                    <button type="button" data-score="4" aria-label="4 звезды">★</button>
                    <button type="button" data-score="5" aria-label="5 звёзд">★</button>
                </div>
                <div class="help" id="rating-hint">Нажмите звезду, чтобы оценить.</div>
            </section>

            <section class="info-section"><h3>Описание</h3><div class="info-text" id="modal-description"></div></section>
            <section class="info-section" id="details-section"><h3>Подробная информация</h3><div class="info-text" id="modal-details"></div></section>
        </div>
    </div>
</div>

<!-- ================= ПОДВАЛ: копирайт + СКРЫТЫЙ ВХОД В АДМИНКУ ================= -->
<footer class="site-footer">
    <div class="container footer-inner">
        <!-- Пять быстрых кликов по копирайту открывают форму входа -->
        <div class="footer-note"><span class="secret-entry" id="secret-entry">© <?= date('Y') ?> STORE.NAME</span> · Сделано с заботой о покупателях</div>
        <?php if (!isset($_SESSION['admin'])): ?>
            <form action="catalog.php" method="post" class="login-form" id="login-form">
                <input type="password" name="password" placeholder="Пароль администратора" required>
                <button class="button" type="submit" name="login">Войти</button>
            </form>
        <?php endif; ?>
    </div>
</footer>

<!-- ================= ЧАТ ПОДДЕРЖКИ ДЛЯ ПОКУПАТЕЛЯ ================= -->
<?php if (!isset($_SESSION['admin'])): ?>
<button class="chat-button" id="chat-button" type="button" aria-label="Открыть чат поддержки">✉</button>
<section class="chat-window" id="chat-window" aria-label="Чат поддержки">
    <div class="chat-head">
        <div><strong>Поддержка</strong><small>Напишите нам — ответим здесь</small></div>
        <button class="chat-close" id="chat-close" type="button" aria-label="Закрыть">×</button>
    </div>
    <input class="chat-name" id="chat-name" maxlength="60" placeholder="Ваше имя — необязательно">
    <div class="chat-messages" id="visitor-messages"><div class="help">Здравствуйте! Напишите вопрос.</div></div>
    <form class="chat-compose" id="visitor-form">
        <textarea id="visitor-input" maxlength="2000" placeholder="Ваше сообщение…" required></textarea>
        <button class="button button-primary" type="submit">➤</button>
    </form>
</section>
<?php endif; ?>

<!-- ============================================================
     JAVASCRIPT — «оживление» страницы
     ============================================================ -->
<script>
/* --- ПОИСК: прячем карточки, которые не содержат введённый текст --- */
const cards = Array.from(document.querySelectorAll('.card'));
const searchInput = document.getElementById('search');
const noResults = document.getElementById('no-search-results');

searchInput.addEventListener('input', () => {
    const query = searchInput.value.trim().toLocaleLowerCase('ru');
    let visible = 0;
    cards.forEach(card => {
        const matches = card.dataset.search.includes(query);
        card.hidden = !matches;
        if (matches) visible++;
    });
    noResults.hidden = visible !== 0;
});

/* --- ОКНО ТОВАРА: элементы и текущее состояние --- */
const modal = document.getElementById('product-modal');
const modalPhoto = document.getElementById('modal-photo');
let currentPhotos = [];      // фото открытого товара
let currentPhotoIndex = 0;   // какое фото показываем сейчас
let currentProductId = '';   // id открытого товара (нужен для корзины и оценок)

/* Показывает фото № index; прячет стрелки, если фото всего одно */
function showPhoto(index) {
    if (!currentPhotos.length) {
        modalPhoto.style.display = 'none';
        document.getElementById('gallery-count').textContent = '';
        document.getElementById('gallery-prev').hidden = true;
        document.getElementById('gallery-next').hidden = true;
        return;
    }
    currentPhotoIndex = (index + currentPhotos.length) % currentPhotos.length;
    modalPhoto.src = currentPhotos[currentPhotoIndex];
    modalPhoto.style.display = '';
    document.getElementById('gallery-count').textContent =
        (currentPhotoIndex + 1) + ' / ' + currentPhotos.length;

    const multiple = currentPhotos.length > 1;
    document.getElementById('gallery-prev').hidden = !multiple;
    document.getElementById('gallery-next').hidden = !multiple;
}

/* Загружает и показывает оценки товара (файл ratings.php) */
async function loadRating(productId) {
    const summary = document.getElementById('rating-summary');
    const hint = document.getElementById('rating-hint');
    const buttons = document.querySelectorAll('#rating-stars button');

    buttons.forEach(button => button.classList.remove('active'));

    try {
        const response = await fetch('ratings.php?product=' + encodeURIComponent(productId), {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'Ошибка оценок');

        summary.textContent = data.count
            ? '★ ' + data.average + ' · оценок: ' + data.count
            : 'Пока нет оценок';
        hint.textContent = data.myRating
            ? 'Ваша оценка: ' + data.myRating + ' из 5. Её можно изменить.'
            : 'Нажмите звезду, чтобы оценить товар.';

        /* Клик по звезде сохраняет оценку через ratings.php */
        buttons.forEach(button => {
            const score = Number(button.dataset.score);
            button.classList.toggle('active', score <= data.myRating);
            button.onclick = async () => {
                const saveResponse = await fetch('ratings.php?product=' + encodeURIComponent(productId), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({score})
                });
                const result = await saveResponse.json();
                if (!result.ok) {
                    alert(result.error || 'Не удалось сохранить оценку.');
                    return;
                }
                await loadRating(productId);
            };
        });
    } catch (error) {
        summary.textContent = 'Не удалось загрузить оценки.';
        console.error(error);
    }
}

/* Заполняет окно товара данными и открывает его */
function openModal(data) {
    document.getElementById('modal-title').textContent = data.title || '';
    document.getElementById('modal-price').textContent = data.price || '';
    document.getElementById('modal-tag').textContent = data.tag || '';
    document.getElementById('modal-category').textContent = 'Категория: ' + (data.category || '');
    document.getElementById('modal-description').textContent = data.description || '';
    document.getElementById('modal-details').textContent = data.details || '';
    document.getElementById('details-section').hidden = !data.details;

    /* Артикул в окне товара: показываем только админу (элемент есть только у него) */
    const skuElement = document.getElementById('modal-sku');
    if (skuElement) {
        skuElement.textContent = data.sku ? '№ ' + data.sku : '';
        skuElement.hidden = !data.sku;
    }

    currentProductId = data.id || '';
    /* Кнопка «В корзину» в окне товара — ведёт в корзину с этим товаром */
        document.getElementById('modal-cart').onclick = () => {
        if (currentProductId) {
            window.location.href = 'cart.php?add=' + encodeURIComponent(currentProductId);
        }
    };
    currentPhotos = Array.isArray(data.photos) ? data.photos : [];
    showPhoto(0);
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';   // запрещаем прокрутку страницы под окном
    if (currentProductId) loadRating(currentProductId);
}

/* Закрывает окно товара */
function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

/* Клик по карточке открывает окно с данными этой карточки */
cards.forEach(card => {
    card.addEventListener('click', () => {
        try {
            openModal(JSON.parse(card.dataset.product));
        } catch (error) {
            console.error('Не удалось открыть карточку товара.', error);
        }
    });
});

// У каждой стрелки ровно один обработчик.
document.getElementById('gallery-prev').addEventListener('click', event => {
    event.stopPropagation();
    if (currentPhotos.length > 1) showPhoto(currentPhotoIndex - 1);
});
document.getElementById('gallery-next').addEventListener('click', event => {
    event.stopPropagation();
    if (currentPhotos.length > 1) showPhoto(currentPhotoIndex + 1);
});

/* Закрытие окна: клик по тёмному фону, клавиша Esc, стрелки листают фото */
modal.addEventListener('click', event => {
    if (event.target === modal) closeModal();
});
modal.querySelector('.modal-content').addEventListener('click', event => event.stopPropagation());
document.addEventListener('keydown', event => {
    if (!modal.classList.contains('open')) return;
    if (event.key === 'Escape') closeModal();
    if (event.key === 'ArrowLeft') showPhoto(currentPhotoIndex - 1);
    if (event.key === 'ArrowRight') showPhoto(currentPhotoIndex + 1);
});

// Скрытый вход: пять нажатий на копирайт.
/* Считаем клики; если в течение 1.8 секунды кликнули 5 раз — показываем форму входа */
const secretEntry = document.getElementById('secret-entry');
const loginForm = document.getElementById('login-form');
if (secretEntry && loginForm) {
    let clicks = 0;
    let clickTimer;
    secretEntry.addEventListener('click', () => {
        clicks++;
        clearTimeout(clickTimer);
        clickTimer = setTimeout(() => clicks = 0, 1800);
        if (clicks >= 5) {
            loginForm.classList.add('visible');
            loginForm.querySelector('input').focus();
            clicks = 0;
        }
    });
}

/* --- Общая функция для чата: отправить запрос и получить ответ в JSON --- */
async function chatRequest(url, options = {}) {
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'Ошибка чата.');
    return data;
}

/* Рисует сообщения чата: пузырьки «покупатель» и «админ» + время */
function renderMessages(container, messages) {
    container.replaceChildren();
    if (!messages || !messages.length) {
        const empty = document.createElement('div');
        empty.className = 'help';
        empty.textContent = 'Сообщений пока нет.';
        container.appendChild(empty);
        return;
    }
    messages.forEach(message => {
        const bubble = document.createElement('div');
        bubble.className = 'chat-message ' + (message.from === 'admin' ? 'admin' : 'visitor');
        const text = document.createElement('span');
        text.textContent = message.text || '';
        bubble.appendChild(text);
        const time = document.createElement('small');
        time.className = 'chat-time';
        time.textContent = new Date((message.time || 0) * 1000)
            .toLocaleString('ru-RU', {hour:'2-digit', minute:'2-digit'});
        bubble.appendChild(time);
        container.appendChild(bubble);
    });
    container.scrollTop = container.scrollHeight;   // прокрутка вниз, к последнему сообщению
}

// Чат покупателя.
(() => {
    const panel = document.getElementById('chat-window');
    if (!panel) return;

    const messages = document.getElementById('visitor-messages');
    const input = document.getElementById('visitor-input');
    const name = document.getElementById('chat-name');

    /* Забирает свежие сообщения с сервера (support.php) */
    async function refresh() {
        try {
            const data = await chatRequest('support.php?action=visitor_messages');
            renderMessages(messages, data.thread.messages);
        } catch (error) {
            console.error(error);
        }
    }

    /* Кнопка-кружок: открыть/закрыть чат */
    document.getElementById('chat-button').addEventListener('click', () => {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            refresh();
            input.focus();
        }
    });
    document.getElementById('chat-close').addEventListener('click', () => panel.classList.remove('open'));

    /* Отправка сообщения покупателем */
    document.getElementById('visitor-form').addEventListener('submit', async event => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        input.disabled = true;

        try {
            await chatRequest('support.php?action=send', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({message, name:name.value.trim()})
            });
            input.value = '';
            await refresh();
        } catch (error) {
            alert(error.message);
        } finally {
            input.disabled = false;
        }
    });

    /* Пока чат открыт — обновляем сообщения каждые 3 секунды */
    setInterval(() => {
        if (panel.classList.contains('open')) refresh();
    }, 3000);
})();

// Чат администратора: отдельные диалоги и очистка выбранной переписки.
(() => {
    const list = document.getElementById('admin-chat-list');
    if (!list) return;

    const messages = document.getElementById('admin-chat-messages');
    const title = document.getElementById('admin-chat-title');
    const form = document.getElementById('admin-chat-form');
    const input = document.getElementById('admin-chat-input');
    const clearButton = document.getElementById('clear-chat');

    let selectedThread = '';   // какой диалог открыт сейчас
    let threadCache = [];      // список всех диалогов
    let busy = false;          // защита от двойной отправки

    /* Обновляет переписку выбранного покупателя */
    async function refreshSelected() {
        if (!selectedThread) return;
        try {
            const data = await chatRequest('support.php?action=messages&thread=' + encodeURIComponent(selectedThread));
            renderMessages(messages, data.thread.messages);
        } catch (error) {
            console.error(error);
        }
    }

  /* Рисует список покупателей слева: имя, последнее сообщение, кнопка удаления */
  function renderList(threads) {
    threadCache = threads || [];
    list.replaceChildren();

    if (!threadCache.length) {
        const empty = document.createElement('div');
        empty.className = 'help';
        empty.style.padding = '12px';
        empty.textContent = 'Пока нет сообщений от покупателей.';
        list.appendChild(empty);
        return;
    }

    threadCache.forEach(thread => {
        const row = document.createElement('div');
        row.className = 'admin-chat-row';

        const selectButton = document.createElement('button');
        selectButton.type = 'button';
        selectButton.className =
            'admin-chat-user' + (thread.id === selectedThread ? ' active' : '');
        selectButton.textContent =
            thread.name || ('Покупатель ' + thread.id.slice(0, 6));

        const preview = document.createElement('small');
        const lastMessage = thread.messages?.at(-1)?.text || 'Новый диалог';
        preview.textContent = lastMessage.length > 55
            ? lastMessage.slice(0, 55) + '…'
            : lastMessage;
        selectButton.appendChild(preview);

        /* Клик по покупателю: открыть его диалог */
        selectButton.addEventListener('click', async () => {
            selectedThread = thread.id;
            title.textContent =
                thread.name || ('Покупатель ' + thread.id.slice(0, 6));
            clearButton.disabled = false;
            renderList(threadCache);
            await refreshSelected();
        });

        /* Корзинка: удалить весь диалог */
        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'admin-chat-delete';
        deleteButton.textContent = '🗑';
        deleteButton.title = 'Удалить диалог';
        deleteButton.setAttribute(
            'aria-label',
            'Удалить диалог с ' + (thread.name || 'покупателем')
        );

        deleteButton.addEventListener('click', async () => {
            const name = thread.name || 'этим покупателем';

            if (!confirm(
                'Удалить весь диалог с ' + name +
                '? Сообщения восстановить будет нельзя.'
            )) {
                return;
            }

            try {
                await chatRequest('support.php?action=delete_thread', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ thread: thread.id })
                });

                if (selectedThread === thread.id) {
                    selectedThread = '';
                    title.textContent = 'Выберите покупателя';
                    messages.replaceChildren();
                    clearButton.disabled = true;
                }

                await refreshList();
            } catch (error) {
                alert(error.message);
            }
        });

        row.appendChild(selectButton);
        row.appendChild(deleteButton);
        list.appendChild(row);
    });
}

    /* Обновляет список диалогов с сервера */
    async function refreshList() {
        try {
            const data = await chatRequest('support.php?action=list');
            renderList(data.threads);
        } catch (error) {
            console.error(error);
        }
    }

    /* Ответ админа покупателю */
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const message = input.value.trim();
        if (busy || !selectedThread || !message) return;

        busy = true;
        try {
            await chatRequest('support.php?action=reply', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({thread:selectedThread, message})
            });
            input.value = '';
            await refreshSelected();
            await refreshList();
        } catch (error) {
            alert(error.message);
        } finally {
            busy = false;
        }
    });

    /* «Очистить диалог»: стереть переписку с выбранным покупателем */
    clearButton.addEventListener('click', async () => {
        if (!selectedThread) return;
        if (!confirm('Очистить переписку с этим покупателем? Это нельзя отменить.')) return;

        try {
            await chatRequest('support.php?action=clear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({thread:selectedThread})
            });
            await refreshSelected();
            await refreshList();
        } catch (error) {
            alert(error.message);
        }
    });

    /* Обновить сразу и список, и открытый диалог */
    async function refresh() {
        await refreshList();
        await refreshSelected();
    }

    refresh();
    setInterval(refresh, 3000);
})();
</script>

</body>
</html>