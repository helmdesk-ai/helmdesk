/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 系统管理（系统设置）相关（英文）
export default {
  系统管理: 'Systems',
  查看系统中所有系统及其成员信息:
    'View all systems in the system and their member info',
  回收站: 'Recycle bin',
  名称: 'Name',
  所有者: 'Owner',
  创建时间: 'Created at',
  成员数: 'Members',
  操作: 'Actions',
  查看详情: 'View details',
  暂无系统: 'No systems',
  客服列表: 'Teammates',
  进入系统: 'Enter system',
  系统详情: 'System details',
  详情: 'Details',
  查看该系统的成员列表: 'View the member list of this system',
  查看并管理该系统的客服与管理员:
    'View and manage teammates and admins in this system',
  添加客服: 'Add teammate',
  选择用户并指定其身份为客服或管理员:
    'Select a user and assign them as a teammate or admin',
  用户: 'User',
  暂无可添加的用户: 'No users available to add',
  身份: 'Role',
  '添加中...': 'Adding...',
  确认添加: 'Confirm add',
  成员: 'Member',
  邮箱: 'Email',
  角色: 'Role',
  加入时间: 'Joined at',
  已删除: 'Deleted',
  暂无成员: 'No members',
  第: 'Page',
  '页，共': 'of',
  上一页: 'Previous',
  下一页: 'Next',
  '确认删除系统？': 'Confirm delete system?',
  '将系统放入回收站，可以后续恢复。':
    'Move this system to the recycle bin. It can be restored later.',
  '确认删除成员？': 'Confirm remove member?',
  '将从该系统移除该成员的访问权限。':
    "This will remove the member's access to this admin.",
  不能删除自己作为所有者的系统: 'You cannot delete a system that you own.',

  系统回收站: 'System recycle bin',
  查看已删除的系统并可恢复: 'View deleted systems and restore',
  '确认恢复系统？': 'Confirm restore system?',
  '恢复后将重新出现在系统管理列表中。':
    'After restoring, it will appear in the system list again.',
  暂无已删除的系统: 'No deleted systems',

  创建一个新的系统并指定所有者: 'Create a new system and set an owner',
  编辑系统: 'Edit system',
  修改系统基础信息并可调整所有者:
    'Update basic system info and optionally change the owner',
} as const;
