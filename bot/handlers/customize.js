// ══ Personnalisation dynamique du bot ══════════════════
const { ActivityType, PermissionFlagsBits } = require('discord.js');
const { SlashCommandBuilder } = require('discord.js');

// Appliquer la personnalisation (nom, avatar, statut)
async function applyPersonalization(client, perso) {
  if (!perso) return;
  try {
    // Statut / présence
    const actTypes = { 0: ActivityType.Playing, 1: ActivityType.Listening, 2: ActivityType.Watching, 3: ActivityType.Streaming, 5: ActivityType.Competing };
    const presences = ['online','idle','dnd','invisible'];
    const presence = presences.includes(perso.presence) ? perso.presence : 'online';
    await client.user.setPresence({
      status: presence,
      activities: [{ name: perso.activityText || 'LTD Sandy Shores', type: actTypes[perso.activityType] || ActivityType.Watching }],
    });

    // Nom du bot (limité à 2 changements/heure par Discord)
    if (perso.name && perso.name !== client.user.username) {
      await client.user.setUsername(perso.name).catch(e => console.warn('setUsername:', e.message));
    }

    // Avatar
    if (perso.avatar && perso.avatar.startsWith('http')) {
      await client.user.setAvatar(perso.avatar).catch(e => console.warn('setAvatar:', e.message));
    }

    console.log(`✅ Personnalisation appliquée : ${perso.activityText}`);
  } catch(err) { console.error('applyPersonalization:', err.message); }
}

// Charger et enregistrer les commandes custom depuis la config
async function registerCustomCommands(client, config) {
  const customCmds = config.customCmds || [];
  const cmdsConfig  = config.cmdsConfig  || {};

  // Retirer les anciennes custom commands du cache
  client.commands.forEach((cmd, name) => {
    if (cmd._isCustom) client.commands.delete(name);
  });

  // Enregistrer les nouvelles
  for (const cmd of customCmds) {
    if (!cmd.name || !cmd.response) continue;

    const command = {
      _isCustom: true,
      data: new SlashCommandBuilder()
        .setName(cmd.name)
        .setDescription(cmd.desc || 'Commande personnalisée'),
      async execute(interaction) {
        // Vérifier le rôle requis
        if (cmd.role) {
          const hasRole = interaction.member.roles.cache.has(cmd.role);
          if (!hasRole) {
            await interaction.reply({ content:'❌ Tu n\'as pas le rôle requis pour cette commande.', ephemeral:true });
            return;
          }
        }

        // Remplacer les variables
        let response = cmd.response
          .replace(/{user}/g, `<@${interaction.user.id}>`)
          .replace(/{username}/g, interaction.user.username)
          .replace(/{serveur}/g, interaction.guild?.name || 'LTD Sandy Shores')
          .replace(/{date}/g, new Date().toLocaleDateString('fr-FR'));

        await interaction.reply({ content: response, ephemeral: cmd.ephemeral });
      },
    };

    client.commands.set(cmd.name, command);
    console.log(`✅ Commande custom chargée : /${cmd.name}`);
  }

  // Appliquer les restrictions de rôle sur les commandes natives
  for (const [cmdName, cfg] of Object.entries(cmdsConfig)) {
    const cmd = client.commands.get(cmdName);
    if (!cmd) continue;
    if (!cfg.on) {
      client.commands.delete(cmdName);
      console.log(`🔇 Commande désactivée : /${cmdName}`);
    }
  }
}

module.exports = { applyPersonalization, registerCustomCommands };
