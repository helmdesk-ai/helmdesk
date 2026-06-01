/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 工作区管理（系统设置）相关（英文）
export default {
  工作区管理: 'Workspaces',
  查看系统中所有工作区及其成员信息:
    'View all workspaces in the system and their member info',
  回收站: 'Recycle bin',
  名称: 'Name',
  所有者: 'Owner',
  创建时间: 'Created at',
  成员数: 'Members',
  操作: 'Actions',
  查看详情: 'View details',
  暂无工作区: 'No workspaces',
  客服列表: 'Teammates',
  进入工作区: 'Enter workspace',
  工作区详情: 'Workspace details',
  详情: 'Details',
  查看该工作区的成员列表: 'View the member list of this workspace',
  查看并管理该工作区的客服与管理员:
    'View and manage teammates and admins in this workspace',
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
  '确认删除工作区？': 'Confirm delete workspace?',
  '将工作区放入回收站，可以后续恢复。':
    'Move this workspace to the recycle bin. It can be restored later.',
  '确认删除成员？': 'Confirm remove member?',
  '将从该工作区移除该成员的访问权限。':
    "This will remove the member's access to this workspace.",
  不能删除自己作为所有者的工作区: 'You cannot delete a workspace that you own.',

  工作区回收站: 'Workspace recycle bin',
  查看已删除的工作区并可恢复: 'View deleted workspaces and restore',
  '确认恢复工作区？': 'Confirm restore workspace?',
  '恢复后将重新出现在工作区管理列表中。':
    'After restoring, it will appear in the workspace list again.',
  暂无已删除的工作区: 'No deleted workspaces',

  创建一个新的工作区并指定所有者: 'Create a new workspace and set an owner',
  编辑工作区: 'Edit workspace',
  修改工作区基础信息并可调整所有者:
    'Update basic workspace info and optionally change the owner',
} as const;
