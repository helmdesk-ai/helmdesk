/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 认证相关（英文）
export default {
  // 认证 - 登录
  登录你的账户: 'Log in to your account',
  在下方输入你的邮箱和密码以登录:
    'Enter your email and password below to log in',
  登录: 'Log in',
  '忘记密码？': 'Forgot password?',
  记住我: 'Remember me',
  '没有账户？': "Don't have an account?",
  注册: 'Sign up',

  // 认证 - 注册
  创建账户: 'Create an account',
  在下方输入你的详细信息以创建账户:
    'Enter your details below to create your account',
  '已有账户？': 'Already have an account?',

  // 认证 - 重置密码
  重置密码: 'Reset password',
  请在下方输入你的新密码: 'Please enter your new password below',
  电子邮件: 'Email',

  // 认证 - 两步验证挑战
  身份验证码: 'Authentication Code',
  '输入你的身份验证器应用程序提供的验证码。':
    'Enter the authentication code provided by your authenticator application.',
  使用恢复码登录: 'login using a recovery code',
  恢复码: 'Recovery Code',
  '请输入你的紧急恢复码之一来确认访问你的账户。':
    'Please confirm access to your account by entering one of your emergency recovery codes.',
  使用身份验证码登录: 'login using an authentication code',
  或者你可以: 'or you can',

  // 认证 - 确认密码
  确认你的密码: 'Confirm your password',
  '这是应用程序的安全区域。请在继续之前确认你的密码。':
    'This is a secure area of the application. Please confirm your password before continuing.',
  确认密码页面: 'Confirm password',

  // 认证 - 忘记密码
  忘记密码: 'Forgot password',
  输入你的电子邮件以接收密码重置链接:
    'Enter your email to receive a password reset link',
  发送密码重置链接: 'Email password reset link',
  '或者，返回': 'Or, return to',

  // 认证 - 验证邮箱
  验证电子邮件: 'Verify email',
  '请点击我们刚刚发送给你的电子邮件中的链接来验证你的电子邮件地址。':
    'Please verify your email address by clicking on the link we just emailed to you.',
  邮箱验证: 'Email verification',
  '新的验证链接已发送到你注册时提供的电子邮件地址。':
    'A new verification link has been sent to the email address you provided during registration.',
  重新发送验证邮件: 'Resend verification email',
  退出登录: 'Log out',
} as const;
