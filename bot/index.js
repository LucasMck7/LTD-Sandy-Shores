require('dotenv').config();
const http = require('http');
const { Client, GatewayIntentBits, Partials, Collection, Events } = require('discord.js');
const cron = require('node-cron');

const { handleCommands }    = require('./handlers/commands');
const { handleFactures }    = require('./handlers/factures');
const { handleFacturesEmployes, CHANNEL_FACTURES_EMP } = require('./handlers/factures_employes');
const { onRoleAdd, onRoleRemove }               = require('./handlers/roles');
const { updateStatsActivity }                   = require('./handlers/stats');
const { handleNotify, handleButtonInteraction, loadMsgIds } = require('./handlers/notify');
const { handleServiceInteraction }              = require('./handlers/service');
const { sendDailyReport, archiveFinishedOrders, checkStationsAlert } = require('./handlers/automations');
const { applyPersonalization, registerCustomCommands } = require('./handlers/customize');
const { getChannel, getConfig }                 = require('./utils/config');

const client = new Client({
  intents:[
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.MessageContent,
    GatewayIntentBits.GuildMembers,
    GatewayIntentBits.GuildMessageReactions,
    GatewayIntentBits.GuildPresences,
  ],
  partials:[Partials.Message, Partials.Channel, Partials.Reaction],
});
client.commands = new Collection();

client.once(Events.ClientReady, async () => {
  console.log('Bot connecte : '+client.user.tag);
  const cfg = await getConfig().catch(()=>({}));
  await registerCustomCommands(client, cfg);
  await applyPersonalization(client);
  await loadMsgIds().catch(()=>{});
  await handleCommands(client).catch(e => console.error('handleCommands:', e.message));

  // Charger les 100 derniers messages du channel factures vendeurs dans le cache
  // Nécessaire pour que MessageUpdate fonctionne
  try {
    const ch = await client.channels.fetch(CHANNEL_FACTURES_EMP).catch(()=>null);
    if (ch) {
      await ch.messages.fetch({limit:100});
      console.log('Cache factures vendeurs OK');
    }
  } catch(e) {}

  cron.schedule('*/5 * * * *',  () => updateStatsActivity(client));
  cron.schedule('0 * * * *',    () => archiveFinishedOrders(client));
  cron.schedule('*/10 * * * *', () => checkStationsAlert(client));
  cron.schedule('0 0 * * *',    () => sendDailyReport(client));
  console.log('CRONs demarres');
  startControlServer();
});

client.on(Events.MessageCreate, async (message) => {
  const FACTURE_BOT_ID = '1395348507291811932';
  const CHAN_ESSENCE   = process.env.CHANNEL_FACTURES_ID || '1441586751310270495';

  const isEssenceChan = message.channelId === CHAN_ESSENCE;
  const isVendeurChan = message.channelId === CHANNEL_FACTURES_EMP;

  if (message.author.bot) {
    if (isEssenceChan && message.author.id === FACTURE_BOT_ID) {
      await handleFactures(message);
      return;
    }
    if (isVendeurChan) {
      await handleFacturesEmployes(message);
      return;
    }
    return;
  }

  if (isVendeurChan) {
    await handleFacturesEmployes(message);
    return;
  }
  // handleCommands enregistre les slash commands au démarrage, pas ici
});

// Détection édition facture vendeur — fonctionne car on a chargé le cache au démarrage
client.on(Events.MessageUpdate, async (oldMsg, newMsg) => {
  if (newMsg.channelId !== CHANNEL_FACTURES_EMP) return;
  try {
    if (newMsg.partial) await newMsg.fetch();
    console.log('MessageUpdate facture vendeur - msg:'+newMsg.id);
    await handleFacturesEmployes(newMsg);
  } catch(e) { console.error('MessageUpdate:', e.message); }
});

client.on(Events.GuildMemberUpdate, async (oldMember, newMember) => {
  const addedRoles   = newMember.roles.cache.filter(r => !oldMember.roles.cache.has(r.id));
  const removedRoles = oldMember.roles.cache.filter(r => !newMember.roles.cache.has(r.id));
  for (const [roleId] of addedRoles)   await onRoleAdd(newMember, roleId);
  for (const [roleId] of removedRoles) await onRoleRemove(newMember, roleId);
});

client.on(Events.InteractionCreate, async (interaction) => {
  if (interaction.isButton() && interaction.customId.startsWith('service_')) {
    await handleServiceInteraction(interaction, client); return;
  }
  if (interaction.isButton()) {
    const handled = await handleButtonInteraction(interaction);
    if (handled) return;
  }
  if (interaction.isChatInputCommand()) {
    const cmd = client.commands.get(interaction.commandName);
    if (cmd) await cmd.execute(interaction, client);
  }
});

function startControlServer() {
  const PORT = process.env.NOTIFY_PORT || 3001;
  http.createServer(async (req, res) => {
    res.setHeader('Content-Type','application/json');
    if (req.method === 'POST') {
      let body = '';
      req.on('data', d => body += d);
      req.on('end', async () => {
        try {
          const data = JSON.parse(body);
          if (req.url === '/notify') {
            res.end(JSON.stringify(await handleNotify(client, data)));
          } else {
            res.end(JSON.stringify({success:false,error:'Route inconnue'}));
          }
        } catch(e) { res.end(JSON.stringify({success:false,error:e.message})); }
      });
    } else if (req.url === '/members') {
      try {
        const GUILD_ID = process.env.GUILD_ID || '1292839020266782732';
        const guild = await client.guilds.fetch(GUILD_ID).catch(()=>null);
        if (!guild) { res.end(JSON.stringify({success:false,error:'Guild introuvable'})); return; }
        await guild.members.fetch();
        const cfg2 = await getConfig().catch(()=>({}));
        const envRoles = [
          process.env.ROLE_RESPONSABLE_VENTES, process.env.ROLE_VENDEUR_EXPERIMENTE,
          process.env.ROLE_VENDEUR_INTERMEDIAIRE, process.env.ROLE_VENDEUR_NOVICE,
          process.env.ROLE_RESPONSABLE_POMPISTE, process.env.ROLE_POMPISTE_EXPERIMENTE,
          process.env.ROLE_POMPISTE_INTERMEDIAIRE, process.env.ROLE_POMPISTE_NOVICE,
        ].filter(Boolean);
        const botRoles = Object.values(cfg2.roles||{}).filter(Boolean);
        const allIds = [...new Set([...envRoles,...botRoles])];
        const members = [];
        guild.members.cache.forEach(m => {
          const mr = m.roles.cache.filter(r => allIds.includes(r.id));
          if (mr.size > 0) members.push({
            discord_id:m.id, username:m.user.username, display_name:m.displayName,
            avatar:m.user.displayAvatarURL({size:64}),
            roles:mr.map(r=>({id:r.id,name:r.name})),
          });
        });
        res.end(JSON.stringify({success:true,members}));
      } catch(e) { res.end(JSON.stringify({success:false,error:e.message})); }
    } else if (req.url === '/status') {
      res.end(JSON.stringify({success:true,status:'online',tag:client.user?.tag}));
    } else if (req.url === '/restart') {
      res.end(JSON.stringify({success:true}));
      setTimeout(()=>process.exit(0), 500);
    } else {
      res.end(JSON.stringify({success:false,error:'Route inconnue'}));
    }
  }).listen(PORT, () => console.log('Serveur controle port '+PORT));
}

client.login(process.env.BOT_TOKEN);
