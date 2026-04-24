export async function onRequest(context) {
    // Contents of context object
    const {
      request, // same as existing Worker API
      env, // same as existing Worker API
      params, // if filename includes [id] or [[path]]
      waitUntil, // same as ctx.waitUntil in existing Worker API
      next, // used for middleware or to fetch assets
      data, // arbitrary space for passing data between middlewares
    } = context;
    if (!params?.id) {
      return new Response('Missing image id.', { status: 400 });
    }

    console.log(params.id)

    const value = await env.img_url.getWithMetadata(params.id);
    if (!value || !value.metadata) {
      return new Response(`Image metadata not found for ID: ${params.id}`, { status: 404 });
    }

    await env.img_url.delete(params.id);
    const info = JSON.stringify(params.id);
    return new Response(info);

  }
