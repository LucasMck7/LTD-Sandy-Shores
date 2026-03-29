const { apiPost } = require('../utils/api');
const { getConfig } = require('../utils/config');

const GRADE_LABELS = {
  ROLE_PATRON:                 'Patron',
  ROLE_CO_PATRON:              'Co-Patron',
  ROLE_RH:                     'Ressources Humaines',
  ROLE_RESPONSABLE_VENTES:     'Responsable des Ventes',
  ROLE_VENDEUR_EXPERIMENTE:    'Vendeur Expérimenté',
  ROLE_VENDEUR_INTERMEDIAIRE:  'Vendeur Intermédiaire',
  ROLE_VENDEUR_NOVICE:         'Vendeur Novice',
  ROLE_RESPONSABLE_POMPISTE:   'Responsable Pompiste',
  ROLE_POMPISTE_EXPERIMENTE:   'Pompiste Expérimenté',
  ROLE_POMPISTE_INTERMEDIAIRE: 'Pompiste Intermédiaire',
  ROLE_POMPISTE_NOVICE:        'Pompiste Novice',
};

async function buildRoleMap() {
  const config = await getConfig().catch(()=>({}));
  const roles  = config.roles || {};
  const map    = {};
  for (const [key, label] of Object.entries(GRADE_LABELS)) {
    const roleId = roles[key];
    if (roleId) map[roleId] = label;
  }
  return map;
}

async function onRoleAdd(member, roleId) {
  const roleMap = await buildRoleMap();
  const poste   = roleMap[roleId];
  if (!poste) return;

  const username  = member.user.username;
  const discordId = member.user.id;
  console.log(`➕ [${poste}] → ${username}`);

  try {
    const r = await apiPost('bot_create_employe', {
      discord_id:  discordId,
      discord_tag: username,
      poste,
      prenom:      member.displayName || username,
      nom:         '(a completer)',
      numero:      0,
    }, true);

    if (r.success) {
      console.log(`✅ Employé créé : ${username} → ${poste}`);
      try {
        await member.send(
          `👋 Bienvenue chez **LTD Sandy Shores** !\n` +
          `Grade : **${poste}**\n` +
          `Complète ta fiche dans le portail employé.`
        );
      } catch {}
    } else if (r.already_exists) {
      await apiPost('bot_update_employe_role', { discord_id: discordId, poste }, true).catch(()=>{});
    }
  } catch (err) { console.error('onRoleAdd:', err.message); }
}

async function onRoleRemove(member, roleId) {
  const roleMap = await buildRoleMap();
  if (!roleMap[roleId]) return;
  try {
    await apiPost('bot_deactivate_employe', { discord_id: member.user.id }, true);
  } catch {}
}

module.exports = { onRoleAdd, onRoleRemove };
