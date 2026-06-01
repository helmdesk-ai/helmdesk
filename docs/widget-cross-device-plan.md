# 网站渠道:分发形态解耦与跨端适配实施方案

> 状态:待评审 / 未动手
> 背景讨论日期:2026-06-01
> 范围:网站渠道（web channel）的「独立页 / 小部件」定位调整,以及小部件的跨端（PC/移动）适配。

---

## 1. 背景与问题

当前产品把网站渠道分成两种形态,并在心智上与设备绑定:

- **独立页（standalone）** → 当作"移动端"用
- **小部件（widget）** → 当作"PC 端"用

参考 SalesSmartly:它**不区分独立页/小部件**,而是用一个小部件同时服务 PC 和移动端,在配置里提供:

- 移动端 / PC 端分别的样式配置
- 移动端聊天页"自动铺满手机屏幕"

### 核心判断

独立页 vs 小部件的真实差异**不是设备,而是分发形态**:

| | 独立页 standalone | 小部件 widget |
|---|---|---|
| 触达方式 | 独立 URL / 二维码 / 链接 | 在客户网站嵌入 script |
| 访客端数据 | `fromModel(..., useWidgetSettings=false)` | `fromModel(..., useWidgetSettings=true)` |
| 多出的配置 | 无 | 入口气泡（位置/图标/角标/toast） |

嵌在客户网站上的小部件,本来就会被移动端浏览器访问。"小部件=PC 专属"的前提是漏的。因此方向是:

1. **保留两个渠道,但重新定位为"分发形态"而非"设备维度"**:
   - 独立页 = 链接/扫码分发（天然全屏,适配所有设备）
   - 小部件 = 嵌入分发（自身做响应式:PC 浮窗 + 移动全屏）
2. **把适配设备的责任收回到小部件自身的响应式里**,而不是让用户为了适配手机去新建独立页。

### 触发器解耦（讨论补充）

移动端常常不通过气泡打开,而是客户业务系统自带"客服"入口。所以小部件要把 **"展示面（聊天页）" 与 "触发器（怎么打开）"** 拆开,触发器给三档:

1. **默认气泡**（零代码）—— 现状
2. **程序化 API**:客户把自己的按钮绑 `HelmDesk.show()` ✅ **已实现**
3. **声明式属性**:`<button data-helmdesk-open>` 自动绑定 —— ❌ 待补

配合一个 **"隐藏默认气泡"** 的入口模式:客户用自己的入口时,我们的气泡不出现、不与其 UI 打架。

---

## 2. 现状盘点（基于代码事实）

### 已经具备的能力（不用重做）

- **程序化打开 API 已存在**:`internal/app/business/publicweb/widget.go` 的加载脚本（`installHostApi`,约 560–604 行）已暴露
  `window.HelmDesk.show() / hide() / track() / shutdown()`,并支持多渠道 `window.HelmDesk.channels[code]`。
- **共享外观已收敛**:`ChannelWebVisitorInterfaceSettingsData`（主题色、标题栏、欢迎语、首页模式等）独立页/小部件共用;
  `theme_color` 已从小部件设置迁出到访客界面设置。两端外观本就不按设备分叉。
- **入口配置已结构化**:`ChannelWebWidgetEntryData`（position / style / icon_size / bottom_offset / 自定义图标对）+
  三个枚举（`WebChannelWidgetEntryPosition` / `WebChannelWidgetEntryStyle` / `WebChannelWidgetIconSize`）。
- **预览草稿系统已贯通**:`useChannelPreviewDraft.ts` + `ChannelLivePreview.vue`,支持 `standalone` / `widget` 两种 `PreviewSurface`。

### 缺失的能力（本方案要补）

| 能力 | 现状 | 缺口 |
|---|---|---|
| 隐藏默认气泡 | 加载脚本无条件 `mountRoot()` 挂载 button | 需要"入口模式"开关 + 脚本按模式跳过气泡 |
| 移动端全屏展开 | `applyEntrySettings` 把面板设为 `min(380px, 100vw-16px) × min(620px, ...)` | 移动端没有真正的全屏接管,小屏体验憋屈 |
| 声明式触发器 | 只有 JS API,无 DOM 属性绑定 | 需要扫描 `data-helmdesk-open` 自动挂 click |

---

## 3. 设计决策

### 决策 1:新增"入口模式"维度

在小部件入口上增加 `mode`（入口模式）:

- `bubble`(默认):渲染默认气泡,行为同现状
- `custom`:**不渲染气泡**,完全依赖客户自己的触发器（`HelmDesk.show()` 或 `data-helmdesk-open`）

`mode = custom` 时,`position / icon_size / 自定义图标` 等"气泡外观"配置在前端应**收起/禁用**(它们对自定义模式无意义),但保留 `unread_badge` / `inline_toast` 的语义讨论（见风险 R3）。

### 决策 2:移动端全屏作为小部件级行为开关

移动端全屏是"展示面"行为,不属于气泡入口外观,放到 `ChannelWebWidgetSettingsData` 级别:

- `mobile_fullscreen_enabled: bool`(默认 `true`)

判定"移动端"由加载脚本用 `window.matchMedia('(max-width: 640px)')` 在客户页运行时决定,**不入库设备类型**。开启后展开时面板铺满视口（`width:100vw; height:100dvh; border-radius:0; inset:0`),关闭时维持现有浮窗尺寸。

> 取舍:不照搬 SalesSmartly 的"PC/移动两套完整样式"。共享外观已覆盖大头,只补"移动端全屏"这一真正有设备差异诉求的点,符合项目规范中「表单克制、不过度分组」的要求。后续若确有需要,再按 R2 增量。

### 决策 3:声明式触发器 `data-helmdesk-open`

加载脚本在 `installHostApi` 后扫描并事件委托 `document` 上的 `[data-helmdesk-open]` 点击 → `setOpen(true)`;用事件委托而非逐元素绑定,兼容 SPA 动态插入的按钮。可选支持 `data-helmdesk-open="<code>"` 指定多渠道场景下的目标实例。

---

## 4. 改动清单（按模块）

### 4.1 后端 Enum（新增）

- **新增** `app/Enums/Channel/Web/WebChannelWidgetEntryMode.php`
  - `enum ... : string implements LabeledEnum`,case:`Bubble = 'bubble'`、`Custom = 'custom'`
  - `label()` 用 `__('channel.web_widget_entry_modes.*')`
  - 提供 `values(): array`（与同目录三个入口枚举一致的写法,供 `Rule::in`）

### 4.2 后端 Data

- **`app/Data/Channel/Web/ChannelWebWidgetEntryData.php`**
  - 构造函数新增 `public WebChannelWidgetEntryMode $mode = WebChannelWidgetEntryMode::Bubble`
  - `defaults()` 补 `'mode' => WebChannelWidgetEntryMode::Bubble->value`
  - `withIconUrls()` 透传 `mode`
- **`app/Data/Channel/Web/ChannelWebWidgetSettingsData.php`**
  - 构造函数新增 `public bool $mobile_fullscreen_enabled = true`
  - `defaults()` 补 `'mobile_fullscreen_enabled' => true`
- **`app/Data/Channel/Web/PublicStandaloneChannelData.php`**
  - 公开下发数据补两个 widget-only 字段（仅 `useWidgetSettings=true` 时填充,与 `entry` 同款三元写法）:
    - `entry_mode`(或直接随 `entry` 对象下发 `mode`,二选一,优先随 `entry`)
    - `mobile_fullscreen_enabled`
  - 独立页模式保持 `null`
- **`app/Data/Channel/Web/FormUpdateWebChannelWidgetData.php`**
  - 新增字段:`public WebChannelWidgetEntryMode $entry_mode = WebChannelWidgetEntryMode::Bubble`、`public bool $mobile_fullscreen_enabled = true`
  - `rules()` 增加:
    - `'entry_mode' => ['required', 'string', Rule::in(WebChannelWidgetEntryMode::values())]`
    - `'mobile_fullscreen_enabled' => ['required', 'boolean']`
  - 注意:`entry_mode=custom` 时 `entry_default_icon_id/active_icon_id` 的成对校验应放宽（自定义模式不需要气泡图标）——用 `exclude_if:entry_mode,custom` 或在 `rules()` 里条件化。

### 4.3 后端 Action

- **`app/Actions/Channel/Web/UpdateWebChannelWidgetAction.php`**
  - `handle()` 把 `entry_mode` 写进 `settings.widget.entry.mode`,`mobile_fullscreen_enabled` 写进 `settings.widget`。
  - 确认保存路径与现有 entry 字段一致（沿用现有 `array_replace_recursive` / Data 合并方式）。
- **`app/Actions/Channel/Web/ShowWebChannelDetailPageAction.php`**
  - `WebChannelFormOptionsData` 增加 `entry_mode_options`（`EnumOptionData::fromCases(WebChannelWidgetEntryMode::cases())`）。
- **`WebChannelData` / `WebChannelWidgetData`**:补 `entry.mode`、`mobile_fullscreen_enabled` 字段,供详情页表单回填。

### 4.4 类型生成

- 改完 Data/Enum 后执行 `php artisan typescript:transform`，刷新 `resources/js/types/generated.d.ts`。

### 4.5 前端 — 配置 tab

- **`resources/js/pages/channel/web/tabs/WidgetTab.vue`**
  - `entry` 子标签顶部加「入口模式」Select（气泡 / 自定义入口）。选 `custom` 时,`v-if`/`disabled` 收起位置、图标大小、自定义图标上传等气泡外观字段。
  - `general` 子标签（或 entry 子标签合适处）加「移动端展开铺满屏幕」`Switch`（`v-model` 绑 `mobile_fullscreen_enabled`,遵循项目 Switch 规范,不用 `:checked`）。
  - `custom` 模式下,在安装代码区追加触发器使用说明（`HelmDesk.show()` 与 `<button data-helmdesk-open>`），文案走 `useI18n()`。
  - 表单提交沿用现有 hidden input + draft 模式,新增字段对应补 hidden input。
- **`useChannelPreviewDraft.ts`**:`ChannelPreviewDraft` 增加 `entryMode: string`、`mobileFullscreenEnabled: boolean`;`createChannelPreviewDraft` 从 `channel.widget.entry.mode` / `channel.widget.mobile_fullscreen_enabled` 初始化。

### 4.6 前端 — 实时预览

- **`resources/js/components/channel/ChannelLivePreview.vue`**
  - `widget` 形态:`entryMode=custom` 时隐藏气泡示意,改为渲染一个"客户自有按钮"占位 + 说明文案,让用户理解此模式没有默认气泡。
  - 可选:`widget` 形态增加「PC / 移动」预览切换,移动预览体现展开全屏效果（与 `mobileFullscreenEnabled` 联动）。**列为 P2,非阻塞。**

### 4.7 Go 加载脚本（核心改动）

文件:`internal/app/business/publicweb/widget.go` 内 `widgetScript`。

- **`normalizeEntrySettings`**:读取 `raw.mode`（白名单 `['bubble','custom']`,默认 `bubble`），并从 bootstrap 顶层读 `mobile_fullscreen`（布尔,默认 true）。把 `mode` 并入返回对象。
- **挂载逻辑（`mountRoot`）**:`mode=custom` 时跳过 `shadow.appendChild(button)`（或挂载后 `button.style.display='none'`），气泡相关的 toast 贴边逻辑相应跳过。panel + API 仍然挂载。
- **`applyEntrySettings`**:加移动端分支 —— `window.matchMedia('(max-width:640px)').matches && mobileFullscreen` 时,panel 设为 `inset:0; width:100vw; height:100dvh; border-radius:0`，并监听 `matchMedia` 变化在旋转/缩放时重算。
- **`installHostApi` 之后**:加 `document` 级事件委托,捕获 `[data-helmdesk-open]` 点击 → 对应实例 `show()`。
- CORS / bootstrap JSON 字段:`WidgetBootstrapHandler` 已透传 `channel`,新增字段随 `PublicStandaloneChannelData` 自动带出,无需改 handler。
- 改完用 `make test-go` 跑 Go 测试（不要直接 `go test`）。

### 4.8 多语言

- **后端** `lang/en/channel.php` + `lang/zh_CN/channel.php`:
  - `web_widget_entry_modes.bubble` / `.custom`
  - 若有校验文案变化,补 `messages.*`
- **前端** `resources/js/locales/{en,zh-CN}/*`:入口模式标签、移动端全屏开关说明、自定义触发器安装指引文案（key 用中文）。

### 4.9 测试

- **PHP（Pest，feature）**:
  - `UpdateWebChannelWidgetAction` 保存 `entry_mode` / `mobile_fullscreen_enabled` 的正向用例 + `Rule::in` 校验用例。
  - `custom` 模式下不强制气泡图标成对的用例。
  - `PublicStandaloneChannelData::fromModel(..., useWidgetSettings=true)` 下发新字段、`=false`（独立页）保持 `null` 的用例。
  - 复用现有 `tests/Feature/Manage/WebChannelManagementTest.php`、`tests/Feature/Channel/Web/PublicStandaloneBootstrapActionTest.php` 既有结构。
- **Go（`make test-go`）**:`mode=custom` 不渲染气泡、`mobile_fullscreen` 在窄视口全屏、`data-helmdesk-open` 触发打开的脚本行为断言（按现有 widget 测试的注入/替换点）。
- 改 PHP 后 `vendor/bin/pint --dirty --format agent`；前端 `npm run lint`。

---

## 5. 分期实施

| 期 | 内容 | 价值 | 依赖 |
|---|---|---|---|
| **P0** | 移动端全屏（4.2 `mobile_fullscreen_enabled` + 4.7 matchMedia 全屏 + 4.5 开关 + 测试） | 最高:一份配置同时服务两端 | 无 |
| **P1** | 入口模式 `bubble/custom` + 隐藏气泡 + `data-helmdesk-open`（4.1/4.2/4.3/4.5/4.6/4.7） | 高:适配客户自有入口 | P0 的 Data 改动 |
| **P2** | 预览的 PC/移动切换、安装文档完善（4.6 可选项） | 中:体验打磨 | P0/P1 |
| **文案定位** | 把"独立页/小部件"的 UI 文案与说明从"设备"改述为"分发形态" | 中:纠正用户心智 | 可独立进行 |

建议先合 **P0**（小、独立、价值最高），验证后再推 P1。

---

## 6. 风险与取舍

- **R1 移动端全屏的关闭手势**:全屏接管后用户怎么退出?面板内需有明确的关闭按钮（iframe 内 `WIDGET_CLOSE` 已有通道,确认移动全屏时该按钮可见可点）。
- **R2 是否要 PC/移动两套样式**:本方案**刻意不做**,只做全屏开关。若后续客户强诉求,再在 `ChannelWebWidgetEntryData` 增量 `mobile_*` 覆盖字段,避免一次性把配置项翻倍。
- **R3 custom 模式下 unread_badge / inline_toast 的归属**:气泡隐藏后,未读角标/提示弹窗失去依附。决策:`custom` 模式下这两项在前端隐藏并按 `false` 下发,或改为依附客户自有按钮（复杂,**暂不做**,P1 先按隐藏处理）。
- **R4 `dvh` 支持范围**:`100dvh` 的浏览器支持范围需在实现前确认；如目标环境不满足，再补 `100vh` 备选声明。
- **R5 多渠道页面**:`data-helmdesk-open` 不带 code 时作用于"最后挂载实例",与现有顶层 API 行为一致;带 `data-helmdesk-open="wch_xxx"` 时精确定位。需在文档说明。

---

## 7. 验收标准

1. 小部件嵌入页在手机浏览器打开,展开后铺满屏幕、可正常关闭;PC 上维持浮窗。
2. 入口模式设为"自定义"后,默认气泡不出现;客户页面上 `HelmDesk.show()` 与 `<button data-helmdesk-open>` 均能拉起对话。
3. 独立页（`useWidgetSettings=false`）下发数据中 `entry / mode / mobile_fullscreen` 仍为 `null`,行为不变。
4. 详情页表单可配置并正确回填新字段;实时预览反映入口模式差异。
5. PHP 与 Go 测试全绿;`pint` / `lint` 无新增告警。

---

## 附:关键文件索引

- Data:`app/Data/Channel/Web/ChannelWebWidgetEntryData.php`、`ChannelWebWidgetSettingsData.php`、`PublicStandaloneChannelData.php`、`FormUpdateWebChannelWidgetData.php`、`WebChannelData.php`、`WebChannelWidgetData.php`
- Enum:`app/Enums/Channel/Web/WebChannelWidgetEntryMode.php`(新)、同目录现有三枚举
- Action:`app/Actions/Channel/Web/UpdateWebChannelWidgetAction.php`、`ShowWebChannelDetailPageAction.php`
- 前端:`resources/js/pages/channel/web/tabs/WidgetTab.vue`、`resources/js/composables/useChannelPreviewDraft.ts`、`resources/js/components/channel/ChannelLivePreview.vue`
- Go:`internal/app/business/publicweb/widget.go`（`widgetScript`、`normalizeEntrySettings`、`applyEntrySettings`、`installHostApi`）
- i18n:`lang/{en,zh_CN}/channel.php`、`resources/js/locales/{en,zh-CN}/*`
</content>
</invoke>
