import {
  Button,
  Card,
  Checkbox,
  type DataColumn,
  DataTable,
  Dialog,
  EmptyState,
  Field,
  IconButton,
  PageHeader,
  Pagination,
  PasswordInput,
  Select,
  Skeleton,
  TextInput,
  toast,
  useConfirm,
} from '@brume-ui/brume-react';
import { Download, Inbox, Pencil, Plus, Trash2 } from 'lucide-react';
import { type ReactNode, useState } from 'react';

/**
 * 元件展示 (workbench menu): every public brume-react component inside the real shell, so the
 * kit can be judged as a whole. Registered in ../components.ts and placed on a Dashboard page
 * by workbench/routes/brume.php. Spans the full dashboard grid.
 */
export function ComponentShowcase() {
  return (
    <div className="flex flex-col gap-4 md:col-span-2 xl:col-span-3" data-component-showcase>
      <p className="text-sm text-text-muted">
        這一頁全部用 brume-react 的公開元件組成，放在真正的後台外框裡。按鈕、彈窗、通知和分頁都可以操作。
      </p>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <ButtonsSection />
        <FieldsSection />
        <FeedbackSection />
        <OverlaysSection />
        <div className="xl:col-span-2">
          <TableSection />
        </div>
        <CardsSection />
        <PageSection />
      </div>
    </div>
  );
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <h3 className="text-sm font-semibold text-text-muted">{title}</h3>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
    </div>
  );
}

const VARIANTS = [
  { variant: 'primary', label: '主要' },
  { variant: 'secondary', label: '次要' },
  { variant: 'ghost', label: '低調' },
  { variant: 'danger', label: '危險' },
] as const;

function ButtonsSection() {
  const [saving, setSaving] = useState(false);
  const save = () => {
    setSaving(true);
    window.setTimeout(() => {
      setSaving(false);
      toast.success('已儲存設定。');
    }, 1200);
  };

  return (
    <Card title="按鈕">
      <div className="flex flex-col gap-5">
        <Group title="樣式">
          {VARIANTS.map(({ variant, label }) => (
            <Button key={variant} variant={variant}>
              {label}
            </Button>
          ))}
        </Group>
        <Group title="尺寸與圖示">
          <Button iconStart={Plus}>新增訂單</Button>
          <Button size="sm" variant="secondary" iconStart={Download}>
            匯出
          </Button>
          <Button size="sm" variant="ghost">
            小尺寸
          </Button>
        </Group>
        <Group title="停用與載入中">
          <Button disabled>停用</Button>
          <Button variant="secondary" disabled>
            停用
          </Button>
          <Button loading={saving} onClick={save}>
            儲存設定
          </Button>
        </Group>
        <Group title="圖示按鈕">
          <IconButton icon={Pencil} label="編輯" onClick={() => toast.info('點了「編輯」。')} />
          <IconButton icon={Trash2} label="刪除" onClick={() => toast.info('點了「刪除」。')} />
          <IconButton icon={Plus} label="新增（停用）" disabled />
        </Group>
      </div>
    </Card>
  );
}

const ROLES = [
  { value: 'admin', label: '管理員' },
  { value: 'editor', label: '編輯' },
  { value: 'viewer', label: '檢視者（停用）', disabled: true },
];

function FieldsSection() {
  const [username, setUsername] = useState('');
  const [role, setRole] = useState<string | undefined>();
  const [notify, setNotify] = useState(true);
  const [agree, setAgree] = useState(false);
  const usernameError =
    username.length > 0 && username.length < 3 ? '帳號至少需要 3 個字元，請再輸入幾個字。' : undefined;

  return (
    <Card title="表單欄位">
      <div className="flex flex-col gap-4">
        <Field label="帳號" required description="輸入少於 3 個字元時會顯示錯誤。" error={usernameError}>
          <TextInput value={username} onChange={(e) => setUsername(e.target.value)} placeholder="例如 herb" />
        </Field>
        <Field label="密碼" description="按右側的眼睛圖示可以顯示密碼。">
          <PasswordInput defaultValue="brume-2026" />
        </Field>
        <Field label="角色">
          <Select options={ROLES} value={role} onValueChange={setRole} placeholder="請選擇角色" />
        </Field>
        <Field label="停用的欄位">
          <TextInput defaultValue="無法編輯" disabled />
        </Field>
        <Field label="有錯誤的欄位" error="這個 Email 已經註冊過，請換一個或直接登入。">
          <TextInput defaultValue="admin@example.com" />
        </Field>
        <div className="flex flex-col gap-2">
          <Checkbox checked={notify} onCheckedChange={setNotify} label="有新訂單時通知我" />
          <Checkbox checked={agree} onCheckedChange={setAgree} label="我已閱讀使用條款" />
          <Checkbox checked onCheckedChange={() => {}} label="停用且已勾選" disabled />
        </div>
      </div>
    </Card>
  );
}

function FeedbackSection() {
  return (
    <Card title="通知">
      <div className="flex flex-col gap-5">
        <Group title="四種等級（成功、資訊、警告會自動消失，錯誤要手動關閉）">
          <Button variant="secondary" onClick={() => toast.success('已儲存訂單 BR-0001。')}>
            成功
          </Button>
          <Button variant="secondary" onClick={() => toast.info('有 3 筆新訂單。')}>
            資訊
          </Button>
          <Button variant="secondary" onClick={() => toast.warning('庫存剩 2 件，記得補貨。')}>
            警告
          </Button>
          <Button variant="secondary" onClick={() => toast.error('儲存失敗：網路中斷，請稍後再試。')}>
            錯誤
          </Button>
        </Group>
      </div>
    </Card>
  );
}

function OverlaysSection() {
  const confirm = useConfirm();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('王小明');
  const [result, setResult] = useState('還沒有操作');
  const dirty = name !== '王小明';

  const close = () => {
    setOpen(false);
    setName('王小明');
  };

  const remove = async () => {
    const ok = await confirm({
      title: '刪除訂單 BR-0001？',
      description: '刪除後無法復原。',
      confirmLabel: '刪除訂單',
      tone: 'danger',
    });
    setResult(ok ? '已刪除訂單 BR-0001' : '已取消刪除');
  };

  const publish = async () => {
    const ok = await confirm({ title: '發布這則公告？', confirmLabel: '發布公告' });
    setResult(ok ? '已發布公告' : '已取消發布');
  };

  return (
    <Card title="彈窗與確認">
      <div className="flex flex-col gap-5">
        <Group title="彈窗（改了名稱再關閉，會先詢問是否放棄變更）">
          <Button variant="secondary" iconStart={Pencil} onClick={() => setOpen(true)}>
            編輯客戶
          </Button>
        </Group>
        <Group title="確認">
          <Button variant="secondary" onClick={publish}>
            發布公告
          </Button>
          <Button variant="danger" iconStart={Trash2} onClick={remove}>
            刪除訂單
          </Button>
        </Group>
        <p className="text-sm text-text-muted" aria-live="polite">
          結果：<span data-confirm-result>{result}</span>
        </p>
      </div>
      <Dialog
        open={open}
        onOpenChange={(next) => (next ? setOpen(true) : close())}
        title="編輯客戶"
        description="修改後按「儲存客戶」才會生效。"
        maximizable
        dirty={dirty}
        footer={
          <>
            <Button variant="secondary" onClick={close}>
              取消
            </Button>
            <Button
              onClick={() => {
                toast.success(`已儲存客戶「${name}」。`);
                setOpen(false);
              }}
            >
              儲存客戶
            </Button>
          </>
        }
      >
        <Field label="名稱">
          <TextInput value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
      </Dialog>
    </Card>
  );
}

interface Product {
  id: string;
  name: string;
  category: string;
  stock: number;
  price: number;
}

const PRODUCT_NAMES = ['霧面馬克杯', '亞麻餐墊', '木質托盤', '手工皂', '玻璃水瓶', '棉麻抱枕', '陶瓷花器'];
const CATEGORIES = ['餐廚', '居家', '清潔'];

const PRODUCTS: Product[] = Array.from({ length: 23 }, (_, i) => ({
  id: String(i + 1),
  name: `${PRODUCT_NAMES[i % PRODUCT_NAMES.length]} ${String(i + 1).padStart(2, '0')}`,
  category: CATEGORIES[i % CATEGORIES.length],
  stock: (i * 37) % 120,
  price: 180 + ((i * 113) % 1700),
}));

const COLUMNS: DataColumn<Product>[] = [
  { key: 'name', header: '商品' },
  { key: 'category', header: '分類', width: '8rem' },
  { key: 'stock', header: '庫存', numeric: true, width: '7rem', cell: (p) => p.stock.toLocaleString('zh-TW') },
  { key: 'price', header: '售價（NT$）', numeric: true, width: '9rem', cell: (p) => p.price.toLocaleString('zh-TW') },
];

const PER_PAGE = 5;

function TableSection() {
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [empty, setEmpty] = useState(false);
  const rows = empty ? [] : PRODUCTS.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  const lastPage = empty ? 1 : Math.ceil(PRODUCTS.length / PER_PAGE);

  return (
    <Card title="資料表與分頁">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap gap-4">
          <Checkbox checked={loading} onCheckedChange={setLoading} label="顯示載入中" />
          <Checkbox
            checked={empty}
            onCheckedChange={(value) => {
              setEmpty(value);
              setPage(1);
            }}
            label="顯示空狀態"
          />
        </div>
        <DataTable
          caption="商品（示範資料）"
          columns={COLUMNS}
          rows={rows}
          loading={loading}
          getRowId={(p) => p.id}
          empty={{
            title: '還沒有商品',
            description: '新增第一個商品後會顯示在這裡。',
            action: (
              <Button iconStart={Plus} onClick={() => setEmpty(false)}>
                新增商品
              </Button>
            ),
          }}
        />
        <div className="flex justify-end">
          <Pagination
            label="商品分頁"
            page={page}
            lastPage={lastPage}
            hrefFor={(n) => `#showcase-page-${n}`}
            onNavigate={(href) => setPage(Number(href.split('-').pop()))}
          />
        </div>
      </div>
    </Card>
  );
}

function CardsSection() {
  const [loaded, setLoaded] = useState(false);

  return (
    <Card title="卡片、空狀態與骨架">
      <div className="flex flex-col gap-5">
        <Group title="空狀態">
          <div className="w-full rounded-card border border-border">
            <EmptyState
              icon={Inbox}
              title="還沒有退貨申請"
              description="客戶送出退貨申請後會顯示在這裡。"
              action={
                <Button variant="secondary" onClick={() => toast.info('這裡會開啟退貨設定。')}>
                  設定退貨規則
                </Button>
              }
            />
          </div>
        </Group>
        <Group title="骨架（載入中的占位）">
          <div className="flex w-full flex-col gap-3">
            {loaded ? (
              <p className="text-sm text-text">本週新增 12 位客戶，其中 5 位已下單。</p>
            ) : (
              <>
                <Skeleton />
                <Skeleton className="w-3/4" />
                <Skeleton className="w-1/2" />
              </>
            )}
            <div>
              <Button size="sm" variant="secondary" onClick={() => setLoaded((v) => !v)}>
                {loaded ? '顯示骨架' : '顯示內容'}
              </Button>
            </div>
          </div>
        </Group>
      </div>
    </Card>
  );
}

function PageSection() {
  return (
    <Card title="頁首與麵包屑">
      <div className="flex flex-col gap-4">
        <p className="text-sm text-text-muted">
          麵包屑由外框依選單自動產生，就在這一頁標題的上方（儀表板 › 元件展示）。下面是頁首元件：標題、說明和右側的操作按鈕。
        </p>
        <div className="rounded-card border border-border p-4 [&>header]:mb-0">
          <PageHeader
            title="訂單"
            description="顯示最近 60 筆訂單。"
            actions={
              <Button size="sm" iconStart={Plus}>
                新增訂單
              </Button>
            }
          />
        </div>
      </div>
    </Card>
  );
}
