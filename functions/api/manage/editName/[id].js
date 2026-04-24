export async function onRequest(context) {
    const { request, params, env } = context;

    if (!params?.id) {
        return new Response(JSON.stringify({ success: false, error: 'Missing image id.' }), {
            status: 400,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    console.log("Request ID:", params.id);

    // 获取元数据
    const value = await env.img_url.getWithMetadata(params.id);
    console.log("Current metadata:", value);

    // 如果记录不存在
    if (!value || !value.metadata) {
        return new Response(JSON.stringify({ success: false, error: `Image metadata not found for ID: ${params.id}` }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 更新文件名（兼容 query 参数 newName）
    const newName = new URL(request.url).searchParams.get("newName") || params.name;
    if (!newName || !newName.trim()) {
        return new Response(JSON.stringify({ success: false, error: 'newName cannot be empty.' }), {
            status: 400,
            headers: { 'Content-Type': 'application/json' },
        });
    }
    value.metadata.fileName = newName.trim();
    await env.img_url.put(params.id, "", { metadata: value.metadata });

    console.log("Updated metadata:", value.metadata);

    return new Response(JSON.stringify({ success: true, fileName: value.metadata.fileName }), {
        headers: { 'Content-Type': 'application/json' },
    });
}
