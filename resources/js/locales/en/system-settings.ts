/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 系统设置相关（英文）
export default {
  // 系统设置页面
  '管理系统基础配置、存储、邮件与供应商能力':
    'Manage system basics, storage, mail, and provider capabilities',
  基础设置: 'General Settings',
  存储设置: 'Storage Settings',
  本地磁盘: 'Local Disk',
  当前服务器: 'Current Server',
  应用本地文件系统: 'Application Local Filesystem',
  邮箱服务器: 'Mail Server',
  '配置系统事务邮件使用的发送驱动和发件身份。':
    'Configure the mail driver and sender identity for system emails.',
  启用系统邮件: 'Enable system mail',
  发件邮箱: 'From address',
  发件人名称: 'From name',
  邮件驱动: 'Mail driver',
  'SMTP 主机': 'SMTP host',
  'SMTP 端口': 'SMTP port',
  加密方式: 'Encryption',
  无: 'None',
  '超时时间（秒）': 'Timeout (seconds)',
  用户名: 'Username',
  密码: 'Password',
  取消清除: 'Cancel clear',
  清除: 'Clear',
  'EHLO 域名': 'EHLO domain',
  'Sendmail 路径': 'Sendmail path',
  'Mailgun 域名': 'Mailgun domain',
  'Mailgun 密钥': 'Mailgun secret',
  'Mailgun 接口地址': 'Mailgun endpoint',
  请求协议: 'Request scheme',
  'Postmark Token': 'Postmark token',
  消息流: 'Message stream',
  'Resend API Key': 'Resend API key',
  'Access Key ID': 'Access Key ID',
  'Secret Access Key': 'Secret Access Key',
  区域: 'Region',
  'Session Token': 'Session Token',
  测试收件邮箱: 'Test recipient email',
  测试: 'Test',
  发送测试邮件: 'Send test email',
  配置系统的基本信息: 'Configure basic system information',
  主机地址: 'Host URL',
  系统名称: 'System Name',
  系统Logo: 'System Logo',
  '上传中...': 'Uploading...',
  版权信息: 'Copyright',
  备案信息: 'ICP Record',
  版本号: 'Version',
  未设置: 'Not set',

  // 存储设置页面
  '选择当前生效的文件存储目标；在操作列中切换后立即生效。':
    'Choose the active file storage target. Use the action column to switch and apply changes immediately.',
  新增配置: 'New profile',
  新增存储配置: 'New storage profile',
  编辑存储配置: 'Edit storage profile',
  '填写对象存储凭据；可先点检测连接确认无误后再保存。':
    'Fill in the object storage credentials. You can run a connection test before saving.',
  '可更新配置名称、访问凭据和自定义域名。':
    'You can update the profile name, access credentials, and custom domain.',
  收起: 'Collapse',
  配置名称: 'Profile name',
  存储提供商: 'Storage Provider',
  查看文档: 'View docs',
  'Access Key / Access Key ID': 'Access Key / Access Key ID',
  'Secret Key / Access Key Secret': 'Secret Key / Access Key Secret',
  Bucket: 'Bucket',
  'Bucket 名称': 'Bucket Name',
  '区域 (Region)': 'Region',
  'Endpoint 地址': 'Endpoint URL',
  '使用外网 Endpoint': 'Use public Endpoint',
  '使用内网 Endpoint': 'Use internal Endpoint',
  '如果服务器和对象存储在同一区域，建议使用内网 Endpoint 以提高速度并节省流量费用':
    'If the server and the object storage are in the same region, using the internal Endpoint is recommended for better performance and lower traffic costs',
  '自定义域名 (可选)': 'Custom Domain (Optional)',
  '如果配置了 CDN 或自定义域名，请在此填写，用于生成文件访问 URL':
    'If you have configured CDN or custom domain, enter it here for generating file access URLs',
  检测连接: 'Test connection',
  '确认删除供应商？': 'Confirm delete provider?',
  '确定要删除该供应商吗？所有关联模型都会一起删除。':
    'Delete this provider and all of its related models?',
  '确认删除模型？': 'Confirm delete model?',
  '确定要删除这个模型吗？删除后无法恢复。':
    'Delete this model? This action cannot be undone.',

  // 存储设置交互
  使用中: 'In Use',
  切换: 'Switch',
  本地存储: 'Local storage',
  '确认删除存储配置？': 'Delete this storage profile?',
  '删除后该配置不再可用；如果有附件还引用着该配置，删除会被拒绝。':
    'Once deleted the profile cannot be used. Deletion is blocked if any attachments still reference it.',
  基础信息: 'Basics',
  '查看 {provider} 接入文档': 'View {provider} setup docs',
  '编辑：{name}': 'Edit: {name}',

  // AI 运行时
  默认模型: 'Default Model',
  连接与凭据: 'Connection & Credentials',
  模型: 'Models',
  暂无供应商: 'No providers',
  内置: 'Built-in',
  添加模型: 'Add model',
  编辑模型: 'Edit model',
  暂无模型: 'No models',
  连接测试成功: 'Connection test succeeded',
  连接测试失败: 'Connection test failed',

  // 添加供应商对话框
  添加供应商: 'Add provider',
  协议: 'Protocol',

  // 模型表单对话框
  模型类型: 'Model type',
  显示名称: 'Display name',

  // AI 供应商（纯凭据）
  'AI 供应商': 'AI Providers',
  '新增 AI 供应商': 'Add AI Provider',
  '系统级 AI 服务凭据，跨工作区共享。模型在「AI 模型管理」页维护。':
    'System-level AI credentials shared across workspaces. Manage models on the AI Models page.',
  品牌: 'Brand',
  凭据状态: 'Credentials',
  已配置: 'Configured',
  未配置: 'Not configured',
  '调整 AI 供应商的名称与凭据。':
    'Adjust the AI provider name and credentials.',
  '选择品牌并填写凭据。': 'Pick a brand and fill in the credentials.',
  '确认删除该 AI 供应商？': 'Delete this AI provider?',
  '删除后该供应商及其下所有模型立即移出全局取用池，且无法恢复。':
    'Once deleted, the provider and all its models leave the global pool immediately and cannot be restored.',
  '已配置，留空则保持不变': 'Configured. Leave blank to keep unchanged.',
  清空凭据: 'Clear credentials',
  '清空后已保存的凭据将被移除，该供应商下的模型将无法继续调用。':
    'After clearing, the saved credentials are removed and models under this provider can no longer be called.',

  // AI 模型管理
  'AI 模型管理': 'AI Models',
  '按用途管理模型，同用途内排序定主备。':
    'Manage models by purpose; ordering within a purpose sets primary/backup.',
  用途: 'Purpose',
  选择供应商: 'Select provider',
  该用途暂无模型: 'No models for this purpose',
  '删除后该模型立即移出全局取用池，且无法恢复。':
    'Once deleted, the model leaves the global pool immediately and cannot be restored.',
  新增模型: 'Add model',
  '选择供应商与用途，填写模型 ID 与显示名称。':
    'Select a provider and purpose, then fill in the model ID and display name.',
  '调整模型的显示名称与启用状态。':
    'Adjust the model display name and active state.',
  预设模型: 'Preset models',
  '选择一个预设模型以填充模型 ID。':
    'Pick a preset model to fill the model ID.',
} as const;
