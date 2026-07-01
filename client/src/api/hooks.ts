import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './client';

export function useProjects() {
  return useQuery({ queryKey: ['projects'], queryFn: api.listProjects });
}

export function useUsage(range = '28d', projectId = 0, userId = 0, breakdown = 'model') {
  return useQuery({ queryKey: ['usage', range, projectId, userId, breakdown], queryFn: () => api.getUsage(range, projectId, userId, breakdown), refetchInterval: 30000 });
}

export function useResetUsage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: api.resetUsage,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['usage'] }),
  });
}

export function useBackfillUsage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: api.backfillUsage,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['usage'] }),
  });
}

export function useApiKey(all = false) {
  return useQuery({ queryKey: ['apikey', all], queryFn: () => api.getApiKey(all) });
}

export function useSaveApiKey() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: api.saveApiKey,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['apikey'] });
      qc.invalidateQueries({ queryKey: ['usage'] });
    },
  });
}

export function useDeleteApiKey() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id?: number) => api.deleteApiKey(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['apikey'] }),
  });
}

export function useSetKeyModel() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: number; ai_model: string }) => api.setKeyModel(vars.id, vars.ai_model),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['apikey'] }),
  });
}

export function useProject(id: number) {
  return useQuery({ queryKey: ['project', id], queryFn: () => api.getProject(id), enabled: id > 0 });
}

export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: api.createProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects'] }),
  });
}

export function useDeleteProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: api.deleteProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects'] }),
  });
}

export function useUpdateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: number; projectName: string; repositoryUrls?: string[] }) =>
      api.updateProject(vars.id, { projectName: vars.projectName, repositoryUrls: vars.repositoryUrls }),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['project', vars.id] });
      qc.invalidateQueries({ queryKey: ['documents', vars.id] });
    },
  });
}

export function useDocuments(id: number) {
  return useQuery({ queryKey: ['documents', id], queryFn: () => api.listDocuments(id), enabled: id > 0 });
}

export function useUploadDocuments(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (files: FileList | File[]) => api.uploadDocuments(id, files),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['documents', id] }),
  });
}

export function useDeleteDocument(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (documentId: number) => api.deleteDocument(id, documentId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['documents', id] }),
  });
}

export function useGenerateSummary(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.generateSummary(id),
    // Write the authoritative summary straight into the cache. A refetch could
    // hit a not-yet-replicated MaxScale replica and show stale "pending".
    onSuccess: (result) =>
      qc.setQueryData(['project', id], (old: any) =>
        old ? { ...old, metadata: result.metadata } : old,
      ),
  });
}

export function useConversations(projectId: number) {
  return useQuery({
    queryKey: ['conversations', projectId],
    queryFn: () => api.listConversations(projectId),
    enabled: projectId > 0,
  });
}

export function useDeleteConversation(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (conversationId: number) => api.deleteConversation(projectId, conversationId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['conversations', projectId] }),
  });
}

export function useChat(projectId: number, conversationId: number) {
  return useQuery({
    queryKey: ['chat', projectId, conversationId],
    queryFn: () => api.getChat(projectId, conversationId),
    enabled: projectId > 0 && conversationId > 0,
    // Avoid an immediate refetch overwriting freshly-appended messages on a
    // lagging MaxScale replica right after sending.
    staleTime: 15000,
  });
}

export function useSendChat(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { conversationId: number; message: string; attachments?: { data: string; name: string; mime: string }[]; signal?: AbortSignal }) =>
      api.sendChat(projectId, vars.conversationId, vars.message, vars.attachments, vars.signal),
    onSuccess: (result) => {
      // Append both messages under the (possibly newly created) conversation.
      qc.setQueryData(['chat', projectId, result.conversation_id], (old: any) => {
        const prev = Array.isArray(old) ? old : [];
        return [...prev, result.userMessage, result.aiMessage];
      });
      // Upsert the thread into the list from the response (avoids a lagged refetch
      // briefly showing a stale/empty title), and move it to the top.
      qc.setQueryData(['conversations', projectId], (old: any) => {
        const list = Array.isArray(old) ? [...old] : [];
        const now = new Date().toISOString();
        const i = list.findIndex((c: any) => c.conversation_id === result.conversation_id);
        const title = result.conversation_title || (i >= 0 ? list[i].title : 'New chat');
        if (i >= 0) list[i] = { ...list[i], title, updated_at: now };
        else list.unshift({ conversation_id: result.conversation_id, title, created_at: now, updated_at: now });
        list.sort((a: any, b: any) => String(b.updated_at || b.created_at).localeCompare(String(a.updated_at || a.created_at)));
        return list;
      });
    },
  });
}
