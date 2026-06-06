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
} as const;
