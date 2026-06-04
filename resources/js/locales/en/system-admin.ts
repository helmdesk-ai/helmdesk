/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 系统设置（英文）
export default {
  // 设置二级侧边栏
  系统: 'System',

  // 自定义属性
  '为联系人和会话扩展自定义字段，记录业务所需信息。':
    'Extend contacts and conversations with custom fields to capture the information your business needs.',
  新增属性: 'Add attribute',
  编辑属性: 'Edit attribute',
  属性名称: 'Attribute name',
  属性标识: 'Attribute key',
  属性类型: 'Attribute type',
  属性描述: 'Description',
  可筛选: 'Filterable',
  暂无自定义属性: 'No custom attributes',
  使用数: 'Usage',
  自定义属性回收站: 'Custom attribute recycle bin',
  查看已删除的自定义属性并可恢复:
    'View deleted custom attributes and restore them',
  暂无已删除的属性: 'No deleted attributes',
  '确认删除属性？': 'Confirm delete attribute?',
  '删除后可在已删除属性中恢复，已有联系人数据会保留。':
    'After deleting, you can restore it from deleted attributes and existing contact values will be kept.',
  '确认恢复属性？': 'Confirm restore attribute?',
  '恢复后将重新出现在自定义属性列表中。':
    'The attribute will return to the custom attribute list after restoring.',
  选项管理: 'Options',
  添加选项: 'Add option',
  属性标识创建后不可修改: 'Attribute key cannot be changed after creation',
  属性类型创建后不可修改: 'Attribute type cannot be changed after creation',

  // 标签
  新增标签: 'Add tag',
  编辑标签: 'Edit tag',
  暂无标签: 'No tags',
  '确认删除标签？': 'Confirm delete tag?',
  来源: 'Source',
  合并标签: 'Merge tags',
  标签回收站: 'Tag recycle bin',
  查看已删除的标签并可恢复: 'View deleted tags and restore them',
  暂无已删除的标签: 'No deleted tags',
  目标标签: 'Target tag',
  被合并标签: 'Tag to merge',
  '合并后被合并标签将被删除，其关联将转移到目标标签。':
    'The merged tag will be deleted and its associations will be transferred to the target tag.',
  '确认恢复标签？': 'Confirm restore tag?',
  '恢复后将重新出现在标签列表中。':
    'The tag will return to the tag list after restoring.',
  '删除后该标签会被移到回收站，可随时恢复；已有联系人和会话关联会保留。':
    'After deletion, the tag will be moved to the recycle bin and can be restored later. Existing contact and conversation associations will be kept.',
  添加标签: 'Add tag',
  暂无可用标签: 'No available tags',
  只看无标签的联系人: 'Only untagged contacts',
  无标签: 'Untagged',
  包含: 'Include',
  排除: 'Exclude',
  包含此标签: 'Include this tag',
  排除此标签: 'Exclude this tag',

  // 系统设置 - 常规设置
  系统名称: 'System Name',
  系统Logo: 'System Logo',

  // 多客服
  客服管理: 'Teammate management',
  '管理可登录后台并参与会话接待的客服账号。':
    'Manage teammate accounts that can sign in to the admin and handle conversations.',
  新增客服: 'Add teammate',
  编辑客服: 'Edit teammate',
  删除时间: 'Deleted at',
  恢复: 'Restore',
  '恢复中...': 'Restoring...',
  确认恢复: 'Confirm restore',
  对外昵称: 'Public display name',
  头像: 'Avatar',
  权限: 'Permissions',
  权限数: 'Permissions',
  邮箱: 'Email',
  登录密码: 'Password',
  确认密码: 'Confirm password',
  创建一个新的客服账号并分配权限:
    'Create a new teammate account and assign permissions',
  '更新客服资料，密码可选不填表示不修改':
    'Update teammate profile. Leave password blank to keep it unchanged',
  '确认删除客服？': 'Confirm delete teammate?',
  '将该客服账号放入回收站，可以后续恢复。':
    'Move this teammate account to the recycle bin. You can restore it later.',
  暂无客服: 'No teammates',
  在线状态: 'Online status',
  最后活跃时间: 'Last active',
  移除: 'Remove',

  // AI 设置
  AI: 'AI',
  大模型供应商: 'LLM Providers',
  '管理系统级大模型供应商凭据与可用模型。':
    'Manage system-wide LLM provider credentials and available models.',
  默认模型: 'Default model',
  大语言模型: 'LLM Model',
  '确认清空凭据？': 'Clear saved credentials?',
  确认清空: 'Clear credentials',
  '清空中...': 'Clearing...',
  更新: 'Update',
  '已配置，输入新值后点击更新':
    'Configured. Enter a new value and click Update.',
  模型: 'Model',
  供应商: 'Provider',
  '模型 ID': 'Model ID',

  // Reception plans
  接待方案: 'Reception plans',
  接待方案表单: 'Reception plan form',
  '先添加方案，再继续完善人设、服务场景等详细配置。':
    'Add the plan first, then continue refining persona and service scenarios.',
  暂无服务场景: 'No service scenarios',
  暂无可用知识库: 'No knowledge bases available',
  '暂无可用 MCP 工具': 'No MCP tools available',
  '确认移除该服务场景？': 'Remove this service scenario?',
  '确认后会从当前表单移除，点击保存后生效；进行中会话沿用其锁定的配置不受影响。':
    'Confirming removes it from the current form. Click Save to apply it; active conversations keep using their locked configuration.',
  场景名称不能为空: 'Scenario name is required',
  场景指令不能为空: 'Scenario instructions are required',
  '修改后会更新当前表单，点击保存后生效。':
    'Changes update the current form. Click Save to apply them.',
  '取消关联后该知识库将不再被此方案检索，点击保存后生效。':
    'After unlinking, this knowledge base will no longer be searched by the plan. Click Save to apply it.',
  '取消关联后该工具将不再被此方案的任务智能体调用，点击保存后生效。':
    'After unlinking, this tool will no longer be available to the task agent. Click Save to apply it.',
  查看详情: 'View details',
  版本号: 'Version',
  创建接待方案: 'Create reception plan',
  添加接待方案: 'Add reception plan',
  方案名称: 'Plan name',
  方案简介: 'Plan description',
  基础信息: 'Basics',
  流程策略: 'Flow strategy',
  接待智能体: 'Reception agent',
  任务智能体: 'Task agent',
  自动回复: 'Auto replies',
  '配置会话进入 AI 或人工接待时自动回复给访客的真实消息。':
    'Configure real replies sent automatically when a conversation enters AI or human reception.',
  'AI 接待欢迎语': 'AI reception greeting',
  '新会话进入 AI 接待或后续由 AI 接管时发送一次，支持 {variable}。':
    'Sent once when a new conversation enters AI reception or is later taken over by AI. Supports {variable}.',
  '您好，我是{{display_name}}，请问有什么可以帮您？':
    'Hi, I am {{display_name}}. How can I help?',
  客服接入欢迎语: 'Teammate joined greeting',
  '会话首次分配给客服时发送一次，支持 {variable}。':
    'Sent once when the conversation is first assigned to a teammate. Supports {variable}.',
  '您好，我是{{teammate_name}}，接下来由我为您服务。':
    'Hi, I am {{teammate_name}}. I will help you from here.',
  客服转接欢迎语: 'Teammate transfer greeting',
  '会话转接给另一位客服时发送一次，支持 {variable}。':
    'Sent once when the conversation is transferred to another teammate. Supports {variable}.',
  '您好，我是{{teammate_name}}，已接手本次会话。':
    'Hi, I am {{teammate_name}}. I have taken over this conversation.',
  语气风格: 'Tone',
  接待智能体模型: 'Reception agent model',
  任务智能体默认模型: 'Task agent default model',
  备用模型: 'Backup models',
  '优先级 {priority}': 'Priority {priority}',
  '未配置备用模型。': 'No backup models configured.',
  上移: 'Move up',
  下移: 'Move down',
  接待指引: 'Reception instructions',
  '请保持友好、简洁、准确；先理解访客问题，再给出可执行答复。不确定时说明限制并询问关键信息。':
    'Be friendly, concise, and accurate. Understand the visitor first, then give actionable help. If uncertain, state the limit and ask for the key detail.',
  'AI 优先': 'AI first',
  人工优先: 'Teammate first',
  '当前接待智能体模型不可用，请重新选择。':
    'The current reception agent model is unavailable. Choose another one.',
  '当前任务智能体默认模型不可用，请重新选择。':
    'The current task agent default model is unavailable. Choose another one.',
  版本: 'Version',
  暂无接待方案: 'No reception plans yet',
  '确认删除接待方案？': 'Delete this reception plan?',
  接待方案回收站: 'Reception plan recycle bin',
  暂无已删除的接待方案: 'No deleted reception plans',
  '确认恢复接待方案？': 'Restore this reception plan?',
  '恢复后将重新出现在接待方案列表中。':
    'After restore, it will appear in the reception plan list again.',
  '删除后该接待方案会被移到回收站，可随时恢复；如果已有渠道或会话正在使用，系统会先阻止删除。':
    'After deletion, this reception plan moves to the recycle bin and can be restored later. If channels or conversations are using it, the system will prevent deletion first.',

  '已基于模板预填字段，可根据业务需要调整后保存。':
    'Fields are pre-filled from the template; adjust as needed before saving.',
  'MCP 工具': 'MCP tools',

  // 知识库
  知识库: 'Knowledge bases',
  创建知识库: 'Create knowledge base',
  编辑知识库: 'Edit knowledge base',
  知识库名称: 'Knowledge base name',
  知识库头像: 'Knowledge base avatar',
  嵌入模型: 'Embedding model',
  不使用重排序: 'Do not use ReRank',
  暂无知识库: 'No knowledge bases yet',
  '确认删除知识库？': 'Delete this knowledge base?',
  '管理当前知识库下的文档；左侧切换分组以查看不同分组的文档。':
    'Manage documents in this knowledge base. Use the groups on the left to switch scopes.',
  '管理当前知识库下的问答；左侧切换分组以查看不同分组的问答。':
    'Manage Q&A entries in this knowledge base. Use the groups on the left to switch scopes.',
  添加问答: 'Add Q&A',
  编辑问答: 'Edit Q&A',
  问题: 'Question',
  答案: 'Answer',
  暂无问答: 'No Q&A entries yet',
  '确认删除该问答？': 'Delete this Q&A entry?',
  '已编辑的内容尚未保存，确定要关闭吗？关闭后修改将丢失。':
    'You have unsaved changes. Are you sure you want to close? Changes will be lost.',
  未知分组: 'Unknown Group',
  新建知识库: 'New knowledge base',
  类别: 'Type',
  描述: 'Description',
  '创建后可在知识库中上传文档或录入问答，为智能体提供检索能力。':
    'After creation, upload documents or add Q&A entries to provide retrieval context for agents.',
  '调整知识库基础信息。': 'Edit knowledge base basics.',
  '删除后将永久移除此知识库及其下所有文档和索引数据，不可恢复。':
    'This will permanently delete this knowledge base, its documents, and its index data. This action cannot be undone.',
  检索配置: 'Retrieval settings',
  知识库检索配置: 'Knowledge base retrieval settings',
  '系统内所有知识库共用这套检索配置。':
    'All knowledge bases in the system share these retrieval settings.',
  标准索引: 'Standard index',
  '为文档建立基础索引，用于日常知识库问答。':
    'Builds the baseline index used for everyday knowledge base answers.',
  请选择嵌入模型: 'Select an embedding model',
  向量维度: 'Vector dimension',
  分段方式: 'Chunking method',
  '单段最大 token': 'Max tokens per chunk',
  '相邻段重叠 token': 'Overlap tokens',
  深度索引: 'Deep index',
  '为长文档建立更深入的层级索引，提升复杂问题的命中效果。':
    'Builds a deeper layered index for long documents and complex questions.',
  摘要模型: 'Summary model',
  请选择摘要模型: 'Select a summary model',
  '重排序模型（可选）': 'Rerank model (optional)',
  确认更新检索配置: 'Update retrieval settings?',
  '保存后会清理系统已有知识库索引，并按新的配置重新构建。':
    'Saving will clear existing system knowledge base indexes and rebuild them with the new settings.',
  继续保存: 'Continue saving',
  请从左侧选择一个知识库: 'Select a knowledge base on the left',
  全部文档: 'All documents',
  全部问答: 'All Q&A',
  文件名: 'File name',
  文件类型: 'File type',
  手动内容: 'Manual content',
  纯文本: 'Plain text',
  大小: 'Size',
  暂无文档: 'No documents yet',
  上传文档: 'Upload documents',
  手动添加文档: 'Add manual document',
  编辑文档: 'Edit document',
  标题: 'Title',
  正文: 'Body',
  检索测试: 'Retrieval test',
  重新索引: 'Reindex',
  '确认删除该文档？': 'Delete this document?',
  '删除后将移除该文档以及它后续生成的索引数据。':
    'This will remove the document and the index data generated from it.',
  分组: 'Group',
  新建分组: 'New group',
  编辑分组: 'Edit group',
  分组名称: 'Group name',
  上级分组: 'Parent group',
  '无（顶级分组）': 'None (top-level group)',
  '分组最多支持两级，即分组下可再创建子分组。':
    'Groups support up to two levels, so each group can contain one level of child groups.',
  移动分组: 'Move group',
  目标分组: 'Target group',
  '确认删除分组？': 'Delete this group?',
  '删除前请先清空子分组。分组下的文档不会被删除。':
    'Remove child groups before deleting. Documents in this group will not be deleted.',
  '该分组下还有子分组，需先清空子分组才能移动到其它分组下。':
    'This group still has child groups. Remove them before moving this group under another group.',
  标准问题: 'Primary question',
  相似问法: 'Similar questions',
  暂无相似问法: 'No similar questions yet',
  '删除后将移除该问答、相似问法和全部答案。':
    'This will remove this Q&A entry, its similar questions, and all answers.',
  '上传失败，请稍后重试。': 'Upload failed. Please try again later.',
  '网络异常，请检查网络后重试。':
    'Network error. Check your connection and try again.',
  '支持 {exts}': 'Supports {exts}',
  '支持 {exts}，单个文件不超过 {size}，单次最多上传 {count} 个。':
    'Supports {exts}. Each file must be under {size}. Upload up to {count} files at a time.',
  '支持 .md / .markdown / .txt / .pdf / .docx / .html / .htm，单个文件不超过 20MB，单次最多上传 20 个。':
    'Supports .md / .markdown / .txt / .pdf / .docx / .html / .htm. Each file must be under 20MB. Upload up to 20 files at a time.',
  '点击选择文件，或将文件拖拽到此处':
    'Click to choose files, or drag files here',
  '已选择 {count} 个文件': '{count} file(s) selected',
  重试失败的文件: 'Retry failed files',
  '已忽略不支持的文件：{names}（仅支持 {exts}）。':
    'Unsupported files ignored: {names}. Supported formats: {exts}.',
  '已忽略超过 {size} 的文件：{names}。': 'Files over {size} ignored: {names}.',
  '单次最多上传 {count} 个文件，多余的已忽略。':
    'Upload up to {count} files at a time. Extra files were ignored.',
  管理系统知识库和文档分组: 'Manage system knowledge bases and document groups',

  网站渠道: 'Web channels',
  网站: 'Website',
  管理系统访客接入渠道: 'Manage system visitor channels',
  系统共享: 'System shared',
  '维护客服常用的标准回复，可在收件箱直接调用。支持个人沉淀与系统共享。':
    'Maintain standard replies for the inbox. Supports personal drafts and system-shared templates.',
  '管理系统接待方案配置，保存即生效。':
    'Manage system reception plan configuration. Saves take effect immediately.',
  '为系统添加一个知识库。': 'Add a knowledge base to the system.',
  创建渠道: 'Create channel',
  '创建一个新的网站渠道。': 'Create a new web channel.',
  渠道名称: 'Channel name',
  接待方式: 'Reception mode',
  客服身份展示: 'Service identity display',
  客服头像: 'Service avatar',
  客服昵称: 'Service nickname',
  '无人接待超时后 AI 接待': 'AI handles unassigned conversations after timeout',
  '无人接待超时时间（秒）': 'Unassigned timeout (seconds)',
  '客服无响应后 AI 接管': 'AI takes over when agent is unresponsive',
  '客服无响应时间（秒）': 'Agent no-response timeout (seconds)',
  '重点客户 AI 谨慎回复': 'Careful AI replies for important customers',
  '重点客户 AI 风险转人工提示': 'AI handoff hint for important customer risks',
  重点客户人工在线优先接待: 'Prefer online human for important customers',
  启用人工服务时间: 'Enable human service hours',
  '当前为非人工服务时间，我会先为您处理；如需客服，将在人工服务时间内继续跟进。':
    'It is currently outside human service hours. I will help first; if an agent is needed, they will follow up during service hours.',
  时区: 'Timezone',
  选择时区: 'Select timezone',
  每周可用时段: 'Weekly schedule',
  休息: 'Off',
  时段外提示: 'Off-hours notice',
  营业时间: 'Business hours',
  周一: 'Mon',
  周二: 'Tue',
  周三: 'Wed',
  周四: 'Thu',
  周五: 'Fri',
  周六: 'Sat',
  周日: 'Sun',
  '中国标准时间 (UTC+8)': 'China Standard Time (UTC+8)',
  '日本标准时间 (UTC+9)': 'Japan Standard Time (UTC+9)',
  '新加坡时间 (UTC+8)': 'Singapore Time (UTC+8)',
  '韩国标准时间 (UTC+9)': 'Korea Standard Time (UTC+9)',
  '香港时间 (UTC+8)': 'Hong Kong Time (UTC+8)',
  '印度标准时间 (UTC+5:30)': 'India Standard Time (UTC+5:30)',
  '英国时间 (UTC+0/+1)': 'United Kingdom Time (UTC+0/+1)',
  '中欧时间 (UTC+1/+2)': 'Central European Time (UTC+1/+2)',
  '德国时间 (UTC+1/+2)': 'Germany Time (UTC+1/+2)',
  '美国东部时间 (UTC-5/-4)': 'US Eastern Time (UTC-5/-4)',
  '美国中部时间 (UTC-6/-5)': 'US Central Time (UTC-6/-5)',
  '美国太平洋时间 (UTC-8/-7)': 'US Pacific Time (UTC-8/-7)',
  '协调世界时 (UTC+0)': 'Coordinated Universal Time (UTC+0)',
  自定义传参: 'Custom parameters',
  登录用户身份签名: 'Signed user identity',
  '配置签名密钥后，你的业务后端可签发 JWT，让已登录用户以可信身份接入客服，防止他人伪造身份。下方的明文参数映射只适合来源、活动等非敏感信息。':
    'After configuring a signing secret, your backend can issue JWTs so logged-in users enter support with a trusted identity. The plain parameter mappings below are only for non-sensitive context such as source or campaign.',
  未生成密钥: 'No secret generated',
  生成密钥: 'Generate secret',
  重置密钥: 'Regenerate secret',
  '确认重置签名密钥？': 'Regenerate signing secret?',
  '重置后现有 token 将立即失效，使用当前密钥签发的访客身份无法再通过校验。系统会立即生成新密钥。':
    'Current tokens become invalid immediately. Visitor identities signed with the current secret will no longer verify. The system will generate a new secret immediately.',
  确认重置: 'Regenerate',
  '把聊天链接 URL 参数或网站嵌入配置参数按映射写入联系人字段、自定义属性或标签。属性必须开启 API 写入，适合记录来源、活动和入口等公开上下文。':
    'Map chat link URL parameters or website embed configuration parameters into contact fields, custom attributes, or tags. Attributes must allow API writes. Use this for source, campaign, entry, and other public context.',
  新增映射: 'Add mapping',
  '配置一个外部参数如何写入联系人资料，保存页面后生效。':
    'Configure how an external parameter is written to contact data. It takes effect after saving the page.',
  参数名: 'Parameter name',
  写入目标: 'Write target',
  写入模式: 'Write mode',
  目标键: 'Target key',
  删除映射: 'Delete mapping',
  暂无自定义传参: 'No custom parameters',
  '属性 Key': 'Attribute key',
  标签模板: 'Tag template',
  '选择已开启 API 写入的自定义属性。属性类型为单选时，参数值需匹配选项 code。':
    'Select a custom attribute with API writes enabled. For single-select attributes, the parameter value must match an option code.',
  '模板支持 {value} 占位（仅允许字母/数字/下划线/连字符 1~40 位）；无占位时使用模板字面量。':
    'The template supports a {value} placeholder with 1-40 letters, numbers, underscores, or hyphens. Without a placeholder, the literal template is used.',
  接入指导: 'Integration guide',
  基本用法: 'Basic usage',
  带参数打开: 'Open with parameters',
  网站嵌入接入指导: 'Website embed integration guide',
  聊天链接接入指导: 'Chat link integration guide',
  '将安装代码添加到你的网站中；PC 端显示浮窗，移动端可自动铺满屏幕。':
    'Add the install snippet to your website. Desktop visitors see a floating window, and mobile visitors can get a full-screen chat.',
  '聊天链接可直接作为链接、按钮跳转地址或二维码落地页使用。':
    'Use the chat link directly as a link, button target, or QR code landing page.',
  '选择“自定义入口”后，默认气泡不会显示；你可以用 HelmDesk.show() 或 data-helmdesk-open 打开聊天。':
    'After choosing Custom entry, the default bubble is hidden. Open chat with HelmDesk.show() or data-helmdesk-open.',
  传入额外参数: 'Pass extra parameters',
  嵌入域名白名单: 'Embed domain allowlist',
  显示未读角标: 'Show unread badge',
  '入口右上角小红点，提示访客有新消息。':
    'A small red dot on the entry indicates new messages.',
  显示提示弹窗: 'Show preview popup',
  '在入口附近弹出新消息预览，点击展开聊天。打扰较强，默认关闭。':
    'Show a new message preview near the entry. Clicking opens the chat. More intrusive, so it is off by default.',
  状态: 'Status',
  操作: 'Actions',
  更多操作: 'More actions',
  取消: 'Cancel',
  '创建中...': 'Creating...',
  返回列表: 'Back to list',
  配置: 'Configure',
  渠道回收站: 'Channel recycle bin',
  查看已删除的渠道并可恢复: 'View deleted channels and restore them',
  '确认恢复渠道？': 'Restore channel?',
  '恢复后将重新出现在网站渠道列表中。':
    'After restore, it will appear in the web channel list again.',
  暂无已删除的渠道: 'No deleted channels',
  接待方案版本: 'Reception plan version',
  未部署接待方案: 'No reception plan deployed',
  管理接待方案: 'Manage reception plans',
  去创建接待方案: 'Create reception plan',
  渠道描述: 'Channel description',
  'AI 回复引用访客消息': 'AI replies quote visitor messages',
  转人工成功提示语: 'Human handoff accepted notice',
  无法转人工提示语: 'Unavailable handoff notice',
  'AI 不可用兜底提示语': 'AI unavailable fallback notice',
  '已为您转接人工客服，请稍等。':
    'I have connected you with a human agent. Please wait a moment.',
  '当前暂无法转接人工，我会继续为您处理。':
    'I cannot connect you with a human agent right now. I will keep helping you.',
  '很抱歉，AI 助手暂时无法为您服务，正在为您转接人工客服，请稍候。':
    'We apologize, but the AI assistant is temporarily unavailable. We are connecting you with a human teammate. Please wait a moment.',
  欢迎语: 'Greeting message',
  接入方式: 'Entry methods',
  网站嵌入代码: 'Website embed snippet',
  聊天链接: 'Chat link',
  聊天链接二维码: 'Chat link QR code',
  网站链接: 'Web link',
  渠道链接: 'Channel link',
  二维码: 'QR code',
  '生成中...': 'Generating...',
  复制安装代码: 'Copy install snippet',
  网站嵌入: 'Website embed',
  查看: 'View',
  启用: 'Enable',
  停用: 'Disable',
  暂无网站渠道: 'No web channels yet',
  最近嵌入: 'Recent embed',
  尚未嵌入: 'Not embedded yet',
  最近一次加载: 'Last loaded on',
  '把 AI 放到你的官网：访客可通过网站嵌入代码或聊天链接和它聊天。':
    'Put AI on your website so visitors can chat through the website embed snippet or a chat link.',
  '确认删除渠道？': 'Delete this channel?',
  '删除后该渠道会被移到已删除列表，可随时恢复；对应的访客入口会暂时不可用。':
    'After deletion, this channel moves to the deleted list and can be restored later. Its visitor entry points will be unavailable for now.',
  未设置: 'Not configured',
  已复制: 'Copied',
  页面标题: 'Page title',
  页面副标题: 'Page subtitle',
  页面图标: 'Page icon',

  // 渠道详情页
  基本信息: 'Basics',
  访客界面: 'Visitor interface',
  主题颜色: 'Theme color',
  首页模式: 'Home screen',
  '开启后访客先看到欢迎屏，再进入聊天':
    'Visitors see a welcome screen first, then enter the chat',
  首页欢迎语: 'Home welcome message',
  实时预览: 'Live preview',
  展开聊天: 'Expand chat',
  收起聊天: 'Collapse chat',
  入口与设备: 'Entry & device',
  入口模式: 'Entry mode',
  入口位置: 'Entry position',
  聊天窗位置: 'Chat window position',
  入口样式: 'Entry style',
  入口图标大小: 'Entry icon size',
  入口底部间距: 'Entry bottom offset',
  默认图标: 'Default icon',
  选中图标: 'Selected icon',
  '不上传则入口使用系统默认图标。':
    'Leave empty to use the system default icon.',
  '展开聊天后入口显示的图标，需与默认图标成对上传。':
    'Shown on the entry while the chat is open; upload it together with the default icon.',
  移动端展开后铺满屏幕: 'Open full screen on mobile',
  '开启后，小部件在手机浏览器中打开聊天时会接管整个屏幕，避免键盘和页面滚动挤压聊天区。':
    'When enabled, the widget takes over the full phone screen when opened, avoiding keyboard and page-scroll pressure on the chat area.',
  '自定义入口会隐藏 HelmDesk 默认气泡，由你网站上的按钮或脚本主动打开聊天窗口。':
    'Custom entry hides the HelmDesk default bubble. Your website button or script opens the chat window instead.',
  '也可以在你的点击事件中调用 HelmDesk.show()；多渠道页面可使用 HelmDesk.channels[code].show()。':
    'You can also call HelmDesk.show() in your click handler. For multi-channel pages, use HelmDesk.channels[code].show().',
  联系客服: 'Contact support',
  客户自有按钮: 'Custom site button',
  复制: 'Copy',
  展示标题栏: 'Show title bar',
  输入框提示内容: 'Input placeholder',
  展示猜你想问: 'Show suggested questions',
  问题列表: 'Question list',
  添加问题: 'Add question',
  '最多展示 6 个问题，空白项不会保存。':
    'Show up to 6 questions. Blank items will not be saved.',
  预览: 'Preview',

  // MCP servers
  'MCP 服务': 'MCP servers',
  添加: 'Add',
  '用 MCP 协议接入外部能力，供不同业务场景调用':
    'Connect external capabilities via MCP for different business workflows.',
  '暂无 MCP 服务': 'No MCP servers',
  '添加 MCP 服务': 'Add MCP server',
  '新增 MCP 服务': 'Add MCP server',
  '编辑 MCP 服务': 'Edit MCP server',
  '调整 MCP 服务的连接配置和认证信息。':
    'Adjust the MCP server connection and authentication settings.',
  '该 MCP 服务暂无工具': 'No tools on this MCP server.',
  传输协议: 'Transport',
  端点地址: 'Endpoint URL',
  认证方式: 'Authentication',
  持有者令牌: 'Bearer token',
  '认证 Header 名': 'Auth header name',
  '认证 Header 值': 'Auth header value',
  '超时（秒）': 'Timeout (seconds)',
  工具数: 'Tools',
  不认证: 'No authentication',
  自定义请求头: 'Custom header',
  同步: 'Sync',
  同步中: 'Syncing',
  已下线: 'Removed',
  远端未提供描述: 'No description provided by remote.',
  工具标注: 'Annotations',
  '删除 MCP 服务 “{name}”？': 'Delete MCP server “{name}”?',
  '删除后将同时移除已缓存的 {count} 个工具记录。':
    'This will also delete {count} cached tool records.',
  文档预览: 'Document preview',
  知识库文档预览: 'Knowledge document preview',
  新窗口打开: 'Open in new window',
  文档预览加载失败: 'Failed to load document preview',

  // 翻译供应商设置
  翻译供应商: 'Translation providers',
  '为接待页消息双向翻译配置外部翻译服务的凭据；同时刻只有一家被设为当前使用。':
    'Configure credentials for an external translation service to translate reception messages in both directions. Only one provider is in use at a time.',
  暂无供应商: 'No providers',
  翻译测试失败: 'Translation test failed',
  '请求失败，请稍后再试': 'Request failed, please try again',
  '网络异常，请检查连接': 'Network error, please check your connection',
  添加翻译供应商: 'Add translation provider',
  编辑翻译供应商: 'Edit translation provider',
  '配置外部翻译服务的协议和凭据。':
    'Configure the external translation protocol and credentials.',
  '调整翻译供应商的名称和凭据。':
    'Adjust the translation provider name and credentials.',
  新增翻译供应商: 'Add translation provider',
  类型: 'Type',
  '清空后已保存的凭据将被移除，接待方案将无法继续使用该供应商翻译。':
    'Saved credentials will be removed, and reception plans will no longer be able to use this provider for translation.',

  访客侧文案自动翻译: 'Translate visitor-facing preset messages',
  '开启后，接待方案内需要发送给访客的预设文案会按访客语言发送。':
    'When enabled, preset messages sent to visitors in this reception plan are translated to the visitor language.',
  访客侧文案翻译失败时: 'When visitor-facing preset message translation fails',
  凭据未配置完整: 'Credentials incomplete',
  不启用翻译: 'No translation',
  '选择该方案用于消息翻译的供应商；不选则该方案不翻译。':
    'Choose the provider this plan uses for message translation; leave empty to disable translation for this plan.',
  显示访客内容: 'Show visitor content',
  显示客服内容: 'Show agent content',
  访客将看到: 'Visitor will see',
  打开自动翻译: 'Turn on auto-translate',
  关闭自动翻译: 'Turn off auto-translate',
  翻译发送: 'Translate before sending',
  关闭翻译发送: 'Turn off translate before sending',
  翻译中: 'Translating',
  翻译失败: 'Translation failed',
  默认接待语言: 'Default reception language',
  请先设置访客语言: 'Set the visitor language first',
  请先确认访客将看到的内容: 'Confirm what the visitor will see before sending',
  访客语言: 'Visitor language',

  // 知识库召回测试
  查询内容: 'Query',
  检索模式: 'Search mode',
  检索: 'Search',
  '共命中 {count} 条': '{count} hits',
  检索路径: 'Retrievers',
  全文: 'Full-text',
  向量: 'Vector',
  已重排: 'Reranked',
  重排: 'Rerank',
  '嵌入失败，已回退全文': 'Embedding failed; fell back to full-text',
  语义命中: 'Semantic hits',
  未命中任何内容: 'No matches',
  未知来源: 'Unknown source',
  得分: 'Score',
  字面命中: 'Literal hits',
  '第 {line} 行': 'Line {line}',
  '检索失败，请稍后再试': 'Search failed, please try again',
} as const;
